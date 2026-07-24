<?php

namespace Tests\Unit;

use App\Services\Nextcloud\PropfindParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PropfindParserTest extends TestCase
{
    #[Test]
    public function it_reads_files_and_directories_from_a_multistatus_response(): void
    {
        $entries = (new PropfindParser)->parse($this->xml(), '/remote.php/dav/files/indexer');

        $this->assertCount(3, $entries);

        [$root, $sub, $file] = $entries;

        $this->assertSame('Akten', $root->path);
        $this->assertTrue($root->isDirectory);

        $this->assertSame('Akten/2019', $sub->path);
        $this->assertTrue($sub->isDirectory);

        $this->assertFalse($file->isDirectory);
        $this->assertSame('Akten/Bericht 2019.pdf', $file->path);
        $this->assertSame('Bericht 2019.pdf', $file->name);
        $this->assertSame('pdf', $file->extension());
        // ETag without quotes and without a W/ prefix.
        $this->assertSame('7f2c9a', $file->etag);
        $this->assertSame(48213, $file->size);
        $this->assertSame('application/pdf', $file->contentType);
        $this->assertSame('101', $file->fileId);
        $this->assertSame('2026-01-14', $file->modifiedAt?->format('Y-m-d'));
    }

    #[Test]
    public function properties_from_a_404_propstat_are_ignored(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0"?>
            <d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
              <d:response>
                <d:href>/dav/notiz.md</d:href>
                <d:propstat>
                  <d:prop><d:getcontentlength>12</d:getcontentlength><d:resourcetype/></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
                <d:propstat>
                  <d:prop><oc:fileid/><d:getcontenttype/></d:prop>
                  <d:status>HTTP/1.1 404 Not Found</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML;

        $entries = (new PropfindParser)->parse($xml, '/dav');

        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]->fileId);
        $this->assertNull($entries[0]->contentType);
        $this->assertSame(12, $entries[0]->size);
    }

    #[Test]
    public function malformed_xml_yields_no_entries_instead_of_an_error(): void
    {
        $this->assertSame([], (new PropfindParser)->parse('<kein>xml', '/dav'));
        $this->assertSame([], (new PropfindParser)->parse('', '/dav'));
    }

    private function xml(): string
    {
        return <<<'XML'
            <?xml version="1.0"?>
            <d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
              <d:response>
                <d:href>/remote.php/dav/files/indexer/Akten/</d:href>
                <d:propstat>
                  <d:prop><d:resourcetype><d:collection/></d:resourcetype><oc:fileid>100</oc:fileid></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/remote.php/dav/files/indexer/Akten/2019/</d:href>
                <d:propstat>
                  <d:prop><d:resourcetype><d:collection/></d:resourcetype><oc:fileid>102</oc:fileid></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/remote.php/dav/files/indexer/Akten/Bericht%202019.pdf</d:href>
                <d:propstat>
                  <d:prop>
                    <d:getetag>W/"7f2c9a"</d:getetag>
                    <d:getlastmodified>Wed, 14 Jan 2026 09:12:00 GMT</d:getlastmodified>
                    <d:getcontentlength>48213</d:getcontentlength>
                    <d:getcontenttype>application/pdf; charset=binary</d:getcontenttype>
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
