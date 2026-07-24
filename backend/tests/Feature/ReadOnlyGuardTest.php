<?php

namespace Tests\Feature;

use App\Exceptions\WriteAttemptException;
use App\Models\NextcloudInstance;
use App\Services\Nextcloud\PropfindParser;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The central promise of NextSearch: Nextcloud files are read, never modified.
 * These tests verify that at the only place where any communication with a
 * Nextcloud happens at all.
 */
class ReadOnlyGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{string}>
     */
    public static function writeMethods(): array
    {
        return array_map(fn (string $method) => [$method], [
            'PUT', 'POST', 'PATCH', 'DELETE', 'MKCOL', 'COPY', 'MOVE',
            'LOCK', 'UNLOCK', 'PROPPATCH', 'REPORT', 'TRACE', 'CONNECT',
        ]);
    }

    #[Test]
    #[DataProvider('writeMethods')]
    public function it_refuses_every_write_method(string $method): void
    {
        $this->expectException(WriteAttemptException::class);

        ReadOnlyWebDavClient::assertReadOnly($method);
    }

    #[Test]
    public function it_allows_exactly_the_four_reading_methods(): void
    {
        $this->assertSame(
            ['GET', 'HEAD', 'PROPFIND', 'OPTIONS'],
            ReadOnlyWebDavClient::ALLOWED_METHODS,
        );

        foreach (ReadOnlyWebDavClient::ALLOWED_METHODS as $method) {
            ReadOnlyWebDavClient::assertReadOnly($method);
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_guard_middleware_stops_a_write_before_it_leaves_the_process(): void
    {
        $reached = false;

        $middleware = ReadOnlyWebDavClient::guardMiddleware();
        $handler = $middleware(function () use (&$reached) {
            $reached = true;

            return null;
        });

        try {
            $handler(new Request('DELETE', 'https://cloud.example/remote.php/dav/files/x/a.pdf'), []);
            $this->fail('A DELETE should not have passed the middleware.');
        } catch (WriteAttemptException) {
            $this->assertFalse($reached, 'Der Request wurde trotz Riegel weitergereicht.');
        }
    }

    #[Test]
    public function listing_a_directory_only_issues_a_propfind(): void
    {
        $mock = new MockHandler([
            new Response(207, ['Content-Type' => 'application/xml'], $this->multistatus()),
        ]);

        $client = new ReadOnlyWebDavClient(new PropfindParser, $mock);
        $entries = $client->list($this->davInstance(), 'Dokumente');

        $this->assertSame('PROPFIND', $mock->getLastRequest()->getMethod());
        $this->assertCount(1, $entries);
        $this->assertSame('Dokumente/rechnung.pdf', $entries[0]->path);
    }

    private function davInstance(): NextcloudInstance
    {
        return NextcloudInstance::factory()->create([
            'base_url' => 'https://cloud.example',
            'username' => 'indexer',
        ]);
    }

    private function multistatus(): string
    {
        return <<<'XML'
            <?xml version="1.0"?>
            <d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
              <d:response>
                <d:href>/remote.php/dav/files/indexer/Dokumente/</d:href>
                <d:propstat>
                  <d:prop>
                    <d:resourcetype><d:collection/></d:resourcetype>
                    <oc:fileid>100</oc:fileid>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/remote.php/dav/files/indexer/Dokumente/rechnung.pdf</d:href>
                <d:propstat>
                  <d:prop>
                    <d:getetag>"abc123"</d:getetag>
                    <d:getlastmodified>Tue, 14 Jan 2026 09:12:00 GMT</d:getlastmodified>
                    <d:getcontentlength>48213</d:getcontentlength>
                    <d:getcontenttype>application/pdf</d:getcontenttype>
                    <d:resourcetype/>
                    <oc:fileid>101</oc:fileid>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML;
    }
}
