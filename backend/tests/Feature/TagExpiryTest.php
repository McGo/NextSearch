<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Tag;
use App\Models\TagAssignment;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Search\SearchIndex;
use App\Services\Tags\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Automatic tags have a shelf life. When it is up, the assignment goes and the
 * documents concerned are pushed back into the index — otherwise the filter
 * would keep finding them.
 */
class TagExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function documentWithTag(Tag $tag, ?string $expiresAt, string $state = Document::STATE_INDEXED): Document
    {
        $folder = WatchedFolder::factory()->create();
        $document = Document::factory()->for($folder, 'folder')->create([
            'nextcloud_instance_id' => $folder->nextcloud_instance_id,
            'state' => $state,
        ]);

        TagAssignment::query()->create([
            'document_id' => $document->id,
            'tag_id' => $tag->id,
            'source' => TagAssignment::SOURCE_AUTO,
            'expires_at' => $expiresAt,
        ]);

        return $document;
    }

    #[Test]
    public function what_has_run_out_goes_and_what_has_not_stays(): void
    {
        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());

        $tag = Tag::factory()->system()->automatic()->create();
        $expired = $this->documentWithTag($tag, now()->subMinute()->toDateTimeString());
        $running = $this->documentWithTag($tag, now()->addDay()->toDateTimeString());

        $removed = app(TagService::class)->sweepExpired();

        $this->assertSame(1, $removed);
        $this->assertDatabaseMissing('document_tag', ['document_id' => $expired->id]);
        $this->assertDatabaseHas('document_tag', ['document_id' => $running->id]);
    }

    #[Test]
    public function a_manual_assignment_without_an_expiry_is_never_swept(): void
    {
        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());

        $tag = Tag::factory()->create();
        $document = $this->documentWithTag($tag, null);

        $this->assertSame(0, app(TagService::class)->sweepExpired());
        $this->assertDatabaseHas('document_tag', ['document_id' => $document->id]);
    }

    #[Test]
    public function the_swept_document_is_written_back_to_the_index(): void
    {
        $tag = Tag::factory()->system()->automatic()->create();
        $document = $this->documentWithTag($tag, now()->subMinute()->toDateTimeString());

        $patched = [];

        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('patch')->andReturnUsing(function (array $documents) use (&$patched) {
            $patched = $documents;
        });
        $this->instance(SearchIndex::class, $index);

        app(TagService::class)->sweepExpired();

        $this->assertSame([[
            'id' => str_replace('-', '', $document->uuid),
            'tag_tokens' => [],
        ]], $patched);
    }

    #[Test]
    public function a_document_that_is_not_indexed_is_left_out_of_the_index_write(): void
    {
        $tag = Tag::factory()->system()->automatic()->create();
        $this->documentWithTag($tag, now()->subMinute()->toDateTimeString(), Document::STATE_PENDING);

        $index = Mockery::mock(SearchIndex::class);
        // A partial update on an id Meilisearch doesn't know would leave a stub
        // behind carrying nothing but tags.
        $index->shouldNotReceive('patch');
        $this->instance(SearchIndex::class, $index);

        $this->assertSame(1, app(TagService::class)->sweepExpired());
    }

    #[Test]
    public function the_command_reports_what_it_removed(): void
    {
        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());

        $tag = Tag::factory()->system()->automatic()->create();
        $this->documentWithTag($tag, now()->subMinute()->toDateTimeString());

        $this->artisan('nextsearch:tags-sweep')
            ->expectsOutputToContain('1 assignment(s) removed.')
            ->assertSuccessful();
    }

    #[Test]
    public function switching_a_tag_off_takes_it_out_of_the_index_without_deleting_the_assignment(): void
    {
        $tag = Tag::factory()->create(['slug' => 'vertrag']);
        $document = $this->documentWithTag($tag, null);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $patched = [];

        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('patch')->andReturnUsing(function (array $documents) use (&$patched) {
            $patched = $documents;
        });
        $this->instance(SearchIndex::class, $index);

        $this->actingAs($admin)
            ->patchJson('/api/tags/'.$tag->uuid, ['enabled' => false])
            ->assertOk();

        $this->assertSame([], $patched[0]['tag_tokens']);
        // The row stays: switching the tag back on brings the assignment back.
        $this->assertDatabaseHas('document_tag', ['document_id' => $document->id, 'tag_id' => $tag->id]);
    }

    #[Test]
    public function deleting_a_tag_takes_its_assignments_with_it(): void
    {
        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());

        $tag = Tag::factory()->create(['slug' => 'vertrag']);
        $document = $this->documentWithTag($tag, null);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->deleteJson('/api/tags/'.$tag->uuid)
            ->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('document_tag', ['document_id' => $document->id]);
    }
}
