<?php

namespace Tests\Feature;

use App\Jobs\CrawlFolderJob;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\IndexRun;
use App\Models\NextcloudInstance;
use App\Models\WatchedFolder;
use App\Services\Nextcloud\PropfindParser;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use App\Services\Search\SearchIndex;
use Carbon\CarbonImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unchanged files must not trigger a processing job — otherwise every run
 * would send the whole corpus through Tika again.
 */
class DeltaDetectionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_unchanged_file_is_recognised_by_etag_and_size(): void
    {
        $document = Document::factory()->make(['etag' => 'abc', 'size' => 100]);

        $this->assertTrue($document->matchesRemote('abc', 100));
        $this->assertFalse($document->matchesRemote('xyz', 100), 'Neue ETag muss neu verarbeitet werden.');
        $this->assertFalse($document->matchesRemote('abc', 200), 'A different size must be reprocessed.');
        $this->assertFalse($document->matchesRemote(null, 100), 'Ohne ETag bleibt nur neu verarbeiten.');
    }

    #[Test]
    public function a_document_that_previously_failed_is_retried(): void
    {
        $document = Document::factory()->make([
            'etag' => 'abc',
            'size' => 100,
            'state' => Document::STATE_FAILED,
        ]);

        $this->assertFalse($document->matchesRemote('abc', 100));
    }

    #[Test]
    public function a_crawl_skips_unchanged_files_and_queues_only_the_changed_one(): void
    {
        Bus::fake([ProcessDocumentJob::class]);

        $folder = $this->folder();
        $run = $this->runFor($folder);

        $unchanged = Document::factory()->for($folder, 'folder')->create([
            'nextcloud_instance_id' => $folder->nextcloud_instance_id,
            'remote_path' => 'Akten/alt.pdf',
            'path_hash' => Document::hashPath('Akten/alt.pdf'),
            'etag' => 'gleich',
            'size' => 1000,
            'state' => Document::STATE_INDEXED,
        ]);

        $changed = Document::factory()->for($folder, 'folder')->create([
            'nextcloud_instance_id' => $folder->nextcloud_instance_id,
            'remote_path' => 'Akten/neu.pdf',
            'path_hash' => Document::hashPath('Akten/neu.pdf'),
            'etag' => 'alt',
            'size' => 1000,
            'state' => Document::STATE_INDEXED,
        ]);

        $this->runCrawl($folder, $run, $this->multistatus());

        Bus::assertDispatchedTimes(ProcessDocumentJob::class, 1);
        Bus::assertDispatched(
            ProcessDocumentJob::class,
            fn (ProcessDocumentJob $job) => $job->document->is($changed),
        );

        $this->assertSame(2, $run->refresh()->files_seen);
        $this->assertSame(1, $run->files_skipped);
        $this->assertSame(1, $run->files_updated);
        $this->assertSame(Document::STATE_INDEXED, $unchanged->refresh()->state);
    }

    #[Test]
    public function files_that_vanished_remotely_leave_database_and_index(): void
    {
        Bus::fake([ProcessDocumentJob::class]);

        $folder = $this->folder();
        $run = $this->runFor($folder);

        $gone = Document::factory()->for($folder, 'folder')->create([
            'nextcloud_instance_id' => $folder->nextcloud_instance_id,
            'remote_path' => 'Akten/geloescht.pdf',
            'path_hash' => Document::hashPath('Akten/geloescht.pdf'),
        ]);

        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('forget')->once()->with([$gone->uuid]);
        $this->app->instance(SearchIndex::class, $index);

        $this->runCrawl($folder, $run, $this->multistatus(), $index);

        $this->assertDatabaseMissing('documents', ['id' => $gone->id]);
        $this->assertSame(1, $run->refresh()->files_removed);
    }

    /**
     * Username and base URL must match the href values in the test document —
     * otherwise the parser won't strip the path prefix.
     */
    private function folder(): WatchedFolder
    {
        return WatchedFolder::factory()
            ->for(
                NextcloudInstance::factory()->create([
                    'base_url' => 'https://cloud.example',
                    'username' => 'indexer',
                ]),
                'instance',
            )
            ->create(['remote_path' => 'Akten']);
    }

    private function runFor(WatchedFolder $folder): IndexRun
    {
        return $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => 'test',
            'started_at' => CarbonImmutable::now(),
            'pending_jobs' => 1,
        ]);
    }

    private function runCrawl(
        WatchedFolder $folder,
        IndexRun $run,
        string $xml,
        ?SearchIndex $index = null,
    ): void {
        $dav = new ReadOnlyWebDavClient(
            new PropfindParser,
            new MockHandler([new Response(207, [], $xml)]),
        );

        $index ??= Mockery::mock(SearchIndex::class)->shouldIgnoreMissing();

        (new CrawlFolderJob($folder, $run))->handle($dav, $index);
    }

    private function multistatus(): string
    {
        return <<<'XML'
            <?xml version="1.0"?>
            <d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
              <d:response>
                <d:href>/remote.php/dav/files/indexer/Akten/</d:href>
                <d:propstat>
                  <d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/remote.php/dav/files/indexer/Akten/alt.pdf</d:href>
                <d:propstat>
                  <d:prop>
                    <d:getetag>"gleich"</d:getetag>
                    <d:getcontentlength>1000</d:getcontentlength>
                    <d:getcontenttype>application/pdf</d:getcontenttype>
                    <d:resourcetype/>
                    <oc:fileid>1</oc:fileid>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              <d:response>
                <d:href>/remote.php/dav/files/indexer/Akten/neu.pdf</d:href>
                <d:propstat>
                  <d:prop>
                    <d:getetag>"anders"</d:getetag>
                    <d:getcontentlength>1000</d:getcontentlength>
                    <d:getcontenttype>application/pdf</d:getcontenttype>
                    <d:resourcetype/>
                    <oc:fileid>2</oc:fileid>
                  </d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
            </d:multistatus>
            XML;
    }
}
