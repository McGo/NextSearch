<?php

namespace Tests\Feature;

use App\Jobs\CrawlFolderJob;
use App\Jobs\ProcessDocumentJob;
use App\Models\ApiToken;
use App\Models\Document;
use App\Models\IndexRun;
use App\Models\NextcloudInstance;
use App\Models\Tag;
use App\Models\TagAssignment;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Nextcloud\PropfindParser;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use App\Services\Search\SearchIndex;
use App\Support\DocumentDto;
use Carbon\CarbonImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A document is the Nextcloud file, not the path it lies under. Renamed or
 * moved — into another watched folder as well — it keeps its uuid, its tags
 * and its link, whatever order the crawls run in.
 */
class FileIdentityTest extends TestCase
{
    use RefreshDatabase;

    private NextcloudInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([ProcessDocumentJob::class]);
        Storage::fake((string) config('nextsearch.preview.disk'));

        $this->instance = NextcloudInstance::factory()->create([
            'base_url' => 'https://cloud.example',
            'username' => 'indexer',
        ]);
    }

    #[Test]
    public function a_renamed_file_keeps_its_document(): void
    {
        $folder = $this->folder('Unterlagen');
        $document = $this->document($folder, 'Unterlagen/scan-0815.pdf', fileId: '77');

        $index = $this->index(function (MockInterface $index) use ($document) {
            $index->shouldReceive('patch')->once()->withArgs(fn (array $docs) => $docs[0]['id'] === str_replace('-', '', $document->uuid)
                && $docs[0]['path'] === 'Unterlagen/2026-10 Rentenbescheid.pdf'
                && $docs[0]['name'] === '2026-10 Rentenbescheid.pdf');
            $index->shouldNotReceive('forget');
        });

        $run = $this->crawl($folder, 'Unterlagen', [['2026-10 Rentenbescheid.pdf', '77', 'e1', 1000]], $index);

        $document->refresh();
        $this->assertSame('Unterlagen/2026-10 Rentenbescheid.pdf', $document->remote_path);
        $this->assertSame(Document::hashPath('Unterlagen/2026-10 Rentenbescheid.pdf'), $document->path_hash);
        $this->assertNull($document->vanished_at);
        $this->assertSame(1, $run->refresh()->files_moved);
        $this->assertSame(0, $run->files_removed);
        $this->assertSame(1, Document::query()->count());

        // Unchanged content: no second trip through Tika.
        Bus::assertNotDispatched(ProcessDocumentJob::class);
    }

    #[Test]
    public function a_move_into_another_folder_takes_uuid_tags_and_visibility_along(): void
    {
        $inbox = $this->folder('Unterlagen/Eingang');
        $erna = $this->folder('Unterlagen/Erna');
        $document = $this->document($inbox, 'Unterlagen/Eingang/bescheid.pdf', fileId: '78');
        $tag = Tag::factory()->create();
        TagAssignment::query()->create([
            'document_id' => $document->id,
            'tag_id' => $tag->id,
            'source' => TagAssignment::SOURCE_MANUAL,
        ]);

        // The new place is crawled first; the old one has not noticed yet.
        $index = $this->index(function (MockInterface $index) use ($erna) {
            $index->shouldReceive('patch')->once()->withArgs(fn (array $docs) => $docs[0]['folder_id'] === $erna->id
                && $docs[0]['folder_label'] === $erna->label);
        });

        $this->crawl($erna, 'Unterlagen/Erna', [['bescheid.pdf', '78', 'e1', 1000]], $index);

        $document->refresh();
        $this->assertSame($erna->id, $document->watched_folder_id);
        $this->assertSame(1, $document->tagAssignments()->count());

        // Shares follow the folder: whoever sees Erna sees the document now.
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->folders()->sync([$erna->id]);

        $this->withToken(ApiToken::issue($user, 'Skript')['plain'])
            ->getJson("/api/v1/documents/{$document->uuid}")
            ->assertOk()
            ->assertJsonPath('document.folder.uuid', $erna->uuid);

        // The old directory, crawled afterwards, has nothing to remove.
        $run = $this->crawl($inbox, 'Unterlagen/Eingang', [], $this->index());
        $this->assertSame(0, $run->refresh()->files_removed);
        $this->assertNull($document->fresh()->vanished_at);
    }

    #[Test]
    public function the_old_place_crawled_first_leaves_the_document_vanished_until_the_new_one_finds_it(): void
    {
        $inbox = $this->folder('Unterlagen/Eingang');
        $erna = $this->folder('Unterlagen/Erna');
        $document = $this->document($inbox, 'Unterlagen/Eingang/bescheid.pdf', fileId: '79', text: 'Rentenbescheid 2026');

        $index = $this->index(fn (MockInterface $index) => $index->shouldReceive('forget')->once()->with([$document->uuid]));
        $this->crawl($inbox, 'Unterlagen/Eingang', [], $index);

        // Out of the search and out of reach — for now.
        $this->assertTrue($document->fresh()->isVanished());
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->app->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());
        $this->withToken(ApiToken::issue($admin, 'Skript')['plain'])
            ->getJson("/api/v1/documents/{$document->uuid}")
            ->assertNotFound();

        // The new place brings it back, from the stored text.
        $index = $this->index(function (MockInterface $index) use ($document, $erna) {
            $index->shouldReceive('upsert')->once()->withArgs(fn (DocumentDto $dto) => $dto->uuid === $document->uuid
                && $dto->folderId === $erna->id
                && $dto->text === 'Rentenbescheid 2026');
        });

        $run = $this->crawl($erna, 'Unterlagen/Erna', [['bescheid.pdf', '79', 'e1', 1000]], $index);

        $document = Document::query()->find($document->id);
        $this->assertNotNull($document);
        $this->assertSame($erna->id, $document->watched_folder_id);
        $this->assertSame(Document::STATE_INDEXED, $document->state);
        $this->assertSame(1, $run->refresh()->files_moved);
        Bus::assertNotDispatched(ProcessDocumentJob::class);
    }

    #[Test]
    public function a_file_changed_on_the_way_is_processed_again_under_the_same_uuid(): void
    {
        $folder = $this->folder('Unterlagen');
        $document = $this->document($folder, 'Unterlagen/alt.pdf', fileId: '80');

        $this->crawl($folder, 'Unterlagen', [['neu.pdf', '80', 'e2', 2000]], $this->index());

        $this->assertSame(Document::STATE_PENDING, $document->fresh()->state);
        Bus::assertDispatched(ProcessDocumentJob::class, fn (ProcessDocumentJob $job) => $job->document->is($document));
    }

    #[Test]
    public function a_new_file_at_a_vanished_documents_old_path_is_a_new_document(): void
    {
        $folder = $this->folder('Unterlagen');
        $old = $this->document($folder, 'Unterlagen/scan.pdf', fileId: '81');
        $old->markVanished();

        $this->crawl($folder, 'Unterlagen', [['scan.pdf', '82', 'e9', 500]], $this->index());

        $new = Document::query()->sole();
        $this->assertNotSame($old->uuid, $new->uuid);
        $this->assertSame('82', $new->oc_file_id);
        $this->assertTrue($old->fresh()->isVanished());
    }

    #[Test]
    public function a_file_moved_onto_a_path_that_held_another_file_takes_its_place(): void
    {
        $folder = $this->folder('Unterlagen');
        $occupant = $this->document($folder, 'Unterlagen/aktuell.pdf', fileId: '83');
        $mover = $this->document($folder, 'Unterlagen/entwurf.pdf', fileId: '84');

        $this->crawl($folder, 'Unterlagen', [['aktuell.pdf', '84', 'e1', 1000]], $this->index());

        $this->assertSame('Unterlagen/aktuell.pdf', $mover->fresh()->remote_path);
        $this->assertTrue($occupant->fresh()->isVanished());
    }

    #[Test]
    public function overlapping_folders_each_keep_their_own_document_for_the_same_file(): void
    {
        $outer = $this->folder('Familie');
        $inner = $this->folder('Familie/Erna');
        $innerDocument = $this->document($inner, 'Familie/Erna/brief.pdf', fileId: '85');

        $this->crawl($outer, 'Familie/Erna', [['brief.pdf', '85', 'e1', 1000]], $this->index(), subPath: 'Erna');

        $this->assertSame($inner->id, $innerDocument->fresh()->watched_folder_id);
        $this->assertSame(2, Document::query()->where('oc_file_id', '85')->count());
    }

    #[Test]
    public function vanished_documents_are_purged_after_the_retention_period(): void
    {
        $folder = $this->folder('Unterlagen');
        $stale = $this->document($folder, 'Unterlagen/a.pdf', fileId: '90');
        $recent = $this->document($folder, 'Unterlagen/b.pdf', fileId: '91');
        $present = $this->document($folder, 'Unterlagen/c.pdf', fileId: '92');

        $stale->markVanished();
        $stale->forceFill(['vanished_at' => now()->subDays(31)])->save();
        $recent->markVanished();

        $this->app->instance(SearchIndex::class, $this->index(
            fn (MockInterface $index) => $index->shouldReceive('forget')->once()->with([$stale->uuid]),
        ));

        $this->artisan('nextsearch:purge-vanished')->assertSuccessful();

        $this->assertNull(Document::query()->withVanished()->find($stale->id));
        $this->assertNotNull(Document::query()->withVanished()->find($recent->id));
        $this->assertNotNull(Document::query()->find($present->id));
    }

    private function folder(string $path): WatchedFolder
    {
        return WatchedFolder::factory()->for($this->instance, 'instance')->create(['remote_path' => $path]);
    }

    private function document(WatchedFolder $folder, string $path, string $fileId, ?string $text = null): Document
    {
        $textKey = null;

        if ($text !== null) {
            $textKey = 'texts/'.$fileId.'.txt';
            Storage::disk((string) config('nextsearch.preview.disk'))->put($textKey, $text);
        }

        return Document::factory()->for($folder, 'folder')->create([
            'nextcloud_instance_id' => $this->instance->id,
            'oc_file_id' => $fileId,
            'remote_path' => $path,
            'path_hash' => Document::hashPath($path),
            'name' => basename($path),
            'etag' => 'e1',
            'size' => 1000,
            'state' => Document::STATE_INDEXED,
            'text_key' => $textKey,
            'first_indexed_at' => now()->subMonth(),
        ]);
    }

    private function index(?callable $expectations = null): SearchIndex
    {
        $index = Mockery::mock(SearchIndex::class);

        if ($expectations !== null) {
            $expectations($index);
        }

        return $index->shouldIgnoreMissing();
    }

    /**
     * Crawls one directory with the given files in it.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $files  name, file id, etag, size
     */
    private function crawl(WatchedFolder $folder, string $directory, array $files, SearchIndex $index, string $subPath = ''): IndexRun
    {
        $run = $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => 'test',
            'started_at' => CarbonImmutable::now(),
            'pending_jobs' => 1,
        ]);

        $dav = new ReadOnlyWebDavClient(
            new PropfindParser,
            new MockHandler([new Response(207, [], $this->multistatus($directory, $files))]),
        );

        (new CrawlFolderJob($folder, $run, $subPath))->handle($dav, $index);

        return $run;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $files
     */
    private function multistatus(string $directory, array $files): string
    {
        $responses = '';

        foreach ($files as [$name, $fileId, $etag, $size]) {
            $href = htmlspecialchars('/remote.php/dav/files/indexer/'.$directory.'/'.rawurlencode($name));
            $responses .= <<<XML
                <d:response>
                  <d:href>{$href}</d:href>
                  <d:propstat>
                    <d:prop>
                      <d:getetag>"{$etag}"</d:getetag>
                      <d:getcontentlength>{$size}</d:getcontentlength>
                      <d:getcontenttype>application/pdf</d:getcontenttype>
                      <d:resourcetype/>
                      <oc:fileid>{$fileId}</oc:fileid>
                    </d:prop>
                    <d:status>HTTP/1.1 200 OK</d:status>
                  </d:propstat>
                </d:response>
                XML;
        }

        return <<<XML
            <?xml version="1.0"?>
            <d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
              <d:response>
                <d:href>/remote.php/dav/files/indexer/{$directory}/</d:href>
                <d:propstat>
                  <d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop>
                  <d:status>HTTP/1.1 200 OK</d:status>
                </d:propstat>
              </d:response>
              {$responses}
            </d:multistatus>
            XML;
    }
}
