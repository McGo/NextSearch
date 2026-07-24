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
 * The only way NextSearch talks to a Nextcloud.
 *
 * Anything not in ALLOWED_METHODS is thrown before a connection is even opened.
 * That makes the "read-only" promise not a statement of intent but a property of
 * the code. The matching test iterates over every write verb.
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
     *                                                                    Tests only: a custom Guzzle handler, e.g. a MockHandler.
     */
    public function __construct(
        private readonly PropfindParser $parser,
        private readonly mixed $handler = null,
    ) {}

    /**
     * List a directory (one level). Nextcloud rejects `Depth: infinity`, so
     * the caller fetches deeper levels recursively.
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

        // The first entry is the requested directory itself.
        $requested = trim($path, '/');

        return array_values(array_filter(
            $entries,
            fn (RemoteEntry $entry) => $entry->path !== $requested,
        ));
    }

    /**
     * Only the folders of one level — the basis for the folder picker in the UI.
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
     * Stream a file into a local file. Returns the number of bytes written.
     */
    public function downloadTo(NextcloudInstance $instance, string $path, string $target): int
    {
        $handle = fopen($target, 'wb');

        if ($handle === false) {
            throw new NextcloudException(sprintf('Target file "%s" is not writable.', $target));
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
     * Open a file as a stream — for passing it through to the browser.
     */
    public function openStream(NextcloudInstance $instance, string $path): StreamInterface
    {
        return $this->send($instance, 'GET', $path, ['stream' => true])->getBody();
    }

    /**
     * The bolt. Throws before anything reaches the network.
     */
    public static function assertReadOnly(string $method): void
    {
        if (! in_array(strtoupper($method), self::ALLOWED_METHODS, true)) {
            throw WriteAttemptException::forMethod($method);
        }
    }

    /**
     * Guzzle middleware with the same check. It hangs on every client of this
     * class, so that a future caller who bypasses `send()` can't accidentally
     * write either.
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
     * The path portion of the WebDAV root that the `href` values in the
     * response prepend and that is stripped off when parsing.
     */
    private function davPathPrefix(NextcloudInstance $instance): string
    {
        return rtrim((string) parse_url($instance->davRoot(), PHP_URL_PATH), '/');
    }
}
