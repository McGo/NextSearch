<?php

namespace App\Services\Nextcloud;

use App\Exceptions\NextcloudException;
use App\Exceptions\WriteAttemptException;
use App\Models\NextcloudInstance;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Der einzige Weg, auf dem NextSearch mit einer Nextcloud spricht.
 *
 * Alles, was nicht in ALLOWED_METHODS steht, wird geworfen, bevor überhaupt
 * eine Verbindung aufgebaut wird. Damit ist die Zusage „ausschließlich lesend"
 * keine Absichtserklärung, sondern eine Eigenschaft des Codes. Der zugehörige
 * Test iteriert über sämtliche Schreibverben.
 */
class ReadOnlyWebDavClient
{
    /** @var list<string> */
    public const ALLOWED_METHODS = ['GET', 'HEAD', 'PROPFIND', 'OPTIONS'];

    private const PROPFIND_BODY = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
          <d:prop>
            <d:getetag/>
            <d:getlastmodified/>
            <d:getcontentlength/>
            <d:getcontenttype/>
            <d:resourcetype/>
            <oc:fileid/>
            <oc:size/>
          </d:prop>
        </d:propfind>
        XML;

    /** @var array<int, Client> */
    private array $clients = [];

    /**
     * @param  (callable(RequestInterface, array): mixed)|null  $handler
     *                                                                    Nur für Tests: ein eigener Guzzle-Handler, etwa ein MockHandler.
     */
    public function __construct(
        private readonly PropfindParser $parser,
        private readonly mixed $handler = null,
    ) {}

    /**
     * Ein Verzeichnis auflisten (eine Ebene). Nextcloud lehnt
     * `Depth: infinity` ab, tiefere Ebenen holt der Aufrufer rekursiv.
     *
     * @return list<RemoteEntry>
     */
    public function list(NextcloudInstance $instance, string $path = ''): array
    {
        $response = $this->send($instance, 'PROPFIND', $path, [
            'headers' => ['Depth' => '1', 'Content-Type' => 'application/xml'],
            'body' => self::PROPFIND_BODY,
        ]);

        $entries = $this->parser->parse(
            (string) $response->getBody(),
            $this->davPathPrefix($instance),
        );

        // Der erste Eintrag ist das angefragte Verzeichnis selbst.
        $requested = trim($path, '/');

        return array_values(array_filter(
            $entries,
            fn (RemoteEntry $entry) => $entry->path !== $requested,
        ));
    }

    /**
     * Nur die Ordner einer Ebene — Grundlage für den Ordner-Picker in der UI.
     *
     * @return list<RemoteEntry>
     */
    public function listDirectories(NextcloudInstance $instance, string $path = ''): array
    {
        $directories = array_filter(
            $this->list($instance, $path),
            fn (RemoteEntry $entry) => $entry->isDirectory,
        );

        usort($directories, fn (RemoteEntry $a, RemoteEntry $b) => strnatcasecmp($a->name, $b->name));

        return array_values($directories);
    }

    /**
     * Metadaten eines einzelnen Eintrags.
     */
    public function stat(NextcloudInstance $instance, string $path): RemoteEntry
    {
        $response = $this->send($instance, 'PROPFIND', $path, [
            'headers' => ['Depth' => '0', 'Content-Type' => 'application/xml'],
            'body' => self::PROPFIND_BODY,
        ]);

        $entries = $this->parser->parse(
            (string) $response->getBody(),
            $this->davPathPrefix($instance),
        );

        if ($entries === []) {
            throw NextcloudException::notFound($path);
        }

        return $entries[0];
    }

    /**
     * Datei in eine lokale Datei streamen. Rückgabe ist die geschriebene Größe.
     */
    public function downloadTo(NextcloudInstance $instance, string $path, string $target): int
    {
        $handle = fopen($target, 'wb');

        if ($handle === false) {
            throw new NextcloudException(sprintf('Zieldatei "%s" nicht beschreibbar.', $target));
        }

        try {
            $this->send($instance, 'GET', $path, ['sink' => $handle]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return (int) filesize($target);
    }

    /**
     * Datei als Stream öffnen — für das Durchreichen an den Browser.
     */
    public function openStream(NextcloudInstance $instance, string $path): StreamInterface
    {
        return $this->send($instance, 'GET', $path, ['stream' => true])->getBody();
    }

    /**
     * Der Riegel. Wirft, bevor irgendetwas das Netz erreicht.
     */
    public static function assertReadOnly(string $method): void
    {
        if (! in_array(strtoupper($method), self::ALLOWED_METHODS, true)) {
            throw WriteAttemptException::forMethod($method);
        }
    }

    /**
     * Guzzle-Middleware mit derselben Prüfung. Sie hängt an jedem Client dieser
     * Klasse, damit auch ein künftiger Aufrufer, der `send()` umgeht, nicht
     * versehentlich schreibt.
     */
    public static function guardMiddleware(): callable
    {
        return static fn (callable $handler) => static function (RequestInterface $request, array $options) use ($handler) {
            self::assertReadOnly($request->getMethod());

            return $handler($request, $options);
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function send(
        NextcloudInstance $instance,
        string $method,
        string $path,
        array $options = [],
    ): ResponseInterface {
        $method = strtoupper($method);
        self::assertReadOnly($method);

        $url = $this->buildUrl($instance, $path);

        try {
            return $this->clientFor($instance)->request($method, $url, $options);
        } catch (ConnectException $e) {
            throw NextcloudException::unreachable($instance->base_url, $e);
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode();

            throw match ($status) {
                401, 403 => NextcloudException::unauthorized(),
                404 => NextcloudException::notFound($path),
                null => NextcloudException::unreachable($instance->base_url, $e),
                default => NextcloudException::unexpectedStatus($status, $path),
            };
        } catch (TransferException $e) {
            throw NextcloudException::unreachable($instance->base_url, $e);
        }
    }

    private function clientFor(NextcloudInstance $instance): Client
    {
        if (isset($this->clients[$instance->id])) {
            return $this->clients[$instance->id];
        }

        $stack = HandlerStack::create($this->handler);
        $stack->push(self::guardMiddleware(), 'nextsearch.read_only');

        return $this->clients[$instance->id] = new Client([
            'handler' => $stack,
            'auth' => [$instance->username, $instance->app_password],
            'verify' => $instance->verify_tls,
            'connect_timeout' => 10,
            'timeout' => 600,
            'http_errors' => true,
            'headers' => [
                'User-Agent' => 'NextSearch (read-only indexer)',
                'OCS-APIRequest' => 'true',
            ],
        ]);
    }

    private function buildUrl(NextcloudInstance $instance, string $path): string
    {
        $segments = array_filter(explode('/', trim($path, '/')), fn ($s) => $s !== '');
        $encoded = implode('/', array_map(rawurlencode(...), $segments));

        return $instance->davRoot().($encoded === '' ? '/' : '/'.$encoded);
    }

    /**
     * Der Pfadanteil der WebDAV-Wurzel, den die `href`-Angaben in der Antwort
     * voranstellen und der beim Parsen abgeschnitten wird.
     */
    private function davPathPrefix(NextcloudInstance $instance): string
    {
        return rtrim((string) parse_url($instance->davRoot(), PHP_URL_PATH), '/');
    }
}
