<?php

namespace Tests\Feature;

use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\IndexRun;
use App\Models\Tag;
use App\Models\TagAssignment;
use App\Models\WatchedFolder;
use App\Services\Extraction\ExtractionResult;
use App\Services\Extraction\TikaClient;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use App\Services\Preview\PreviewRenderer;
use App\Services\Search\SearchIndex;
use App\Services\Tags\TagService;
use App\Support\DocumentDto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The "Neu" tag hangs itself on documents that are indexed for the first time —
 * but not on the stock a folder starts out with, and not again on a rebuild.
 */
class AutoTagTest extends TestCase
{
    use RefreshDatabase;

    private function autoTag(int $minutes = 60): Tag
    {
        return Tag::factory()->system()->automatic($minutes)->create();
    }

    private function documentIn(WatchedFolder $folder): Document
    {
        return Document::factory()->for($folder, 'folder')->create([
            'nextcloud_instance_id' => $folder->nextcloud_instance_id,
        ]);
    }

    #[Test]
    public function nothing_is_tagged_while_the_folder_has_no_baseline(): void
    {
        $this->autoTag();
        $folder = WatchedFolder::factory()->create(['baseline_at' => null]);
        $document = $this->documentIn($folder);

        app(TagService::class)->applyAutoTags($document);

        $this->assertDatabaseCount('document_tag', 0);
    }

    #[Test]
    public function after_the_baseline_a_new_document_gets_the_tag_with_an_expiry(): void
    {
        $tag = $this->autoTag(minutes: 90);
        $folder = WatchedFolder::factory()->create(['baseline_at' => now()->subDay()]);
        $document = $this->documentIn($folder);

        app(TagService::class)->applyAutoTags($document);

        $assignment = TagAssignment::query()->firstOrFail();

        $this->assertSame($tag->id, $assignment->tag_id);
        $this->assertNull($assignment->user_id, 'Eine Automatik-Zuweisung gehört niemandem persönlich.');
        $this->assertSame(TagAssignment::SOURCE_AUTO, $assignment->source);
        $this->assertEqualsWithDelta(90, now()->diffInMinutes($assignment->expires_at), 1);
    }

    #[Test]
    public function a_disabled_automatic_tag_stays_out_of_it(): void
    {
        $this->autoTag()->forceFill(['enabled' => false])->save();
        $folder = WatchedFolder::factory()->create(['baseline_at' => now()->subDay()]);

        app(TagService::class)->applyAutoTags($this->documentIn($folder));

        $this->assertDatabaseCount('document_tag', 0);
    }

    #[Test]
    public function the_first_finished_run_draws_the_baseline_and_later_runs_leave_it_alone(): void
    {
        $folder = WatchedFolder::factory()->create(['baseline_at' => null]);

        $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => 'test',
            'started_at' => now(),
        ])->complete();

        $baseline = $folder->fresh()->baseline_at;
        $this->assertNotNull($baseline);

        $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => 'test',
            'started_at' => now(),
        ])->complete();

        $this->assertTrue($baseline->equalTo($folder->fresh()->baseline_at));
    }

    #[Test]
    public function processing_marks_the_first_indexing_and_does_not_repeat_the_tag(): void
    {
        $tag = $this->autoTag();
        $folder = WatchedFolder::factory()->create(['baseline_at' => now()->subDay()]);
        $document = $this->documentIn($folder);
        $document->forceFill(['state' => Document::STATE_PENDING, 'first_indexed_at' => null])->save();

        $this->process($document);

        $document->refresh();
        $firstIndexedAt = $document->first_indexed_at;

        $this->assertNotNull($firstIndexedAt);
        $this->assertDatabaseCount('document_tag', 1);

        // Someone takes "Neu" off by hand, then the document is reprocessed —
        // as a rebuild does. It must not come back.
        TagAssignment::query()->delete();
        $this->process($document->fresh());

        $this->assertDatabaseCount('document_tag', 0);
        $this->assertTrue($firstIndexedAt->equalTo($document->fresh()->first_indexed_at));
        $this->assertSame($tag->slug, Tag::SLUG_NEW);
    }

    #[Test]
    public function the_tokens_of_a_document_travel_with_the_index_write(): void
    {
        $tag = $this->autoTag();
        $folder = WatchedFolder::factory()->create(['baseline_at' => now()->subDay()]);
        $document = $this->documentIn($folder);
        $document->forceFill(['state' => Document::STATE_PENDING, 'first_indexed_at' => null])->save();

        $written = null;

        $this->process($document, function (DocumentDto $dto) use (&$written) {
            $written = $dto->toSearchDocument();
        });

        $this->assertSame(['t'.$tag->id], $written['tag_tokens']);
    }

    /**
     * Runs one document through the processing job with the outside world
     * mocked away: no Nextcloud, no Tika, no preview renderer.
     */
    private function process(Document $document, ?callable $onUpsert = null): void
    {
        Storage::fake((string) config('nextsearch.preview.disk'));

        $dav = Mockery::mock(ReadOnlyWebDavClient::class);
        $dav->shouldReceive('downloadTo')->andReturn(0);

        $tika = Mockery::mock(TikaClient::class);
        $tika->shouldReceive('extract')->andReturn(new ExtractionResult('Rechnung über Dachziegel.'));

        $previews = Mockery::mock(PreviewRenderer::class);
        $previews->shouldReceive('render')->andReturnNull();

        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('upsert')->andReturnUsing(function (DocumentDto $dto) use ($onUpsert) {
            if ($onUpsert !== null) {
                $onUpsert($dto);
            }
        });
        $index->shouldIgnoreMissing();

        $run = $document->folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => 'test',
            'started_at' => now(),
            'pending_jobs' => 1,
        ]);

        (new ProcessDocumentJob($document, $run))->handle(
            $dav, $tika, $previews, $index, app(TagService::class),
        );
    }
}
