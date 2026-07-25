<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Search\SearchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrphanCleanupTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function opening_a_document_that_is_gone_heals_the_index_and_reports_cleanly(): void
    {
        $uuid = '019f94d9-daf4-70d7-98ac-bd33c987f9f4';

        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('forget')->once()->with([$uuid]);
        $this->instance(SearchIndex::class, $index);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->getJson("/api/documents/{$uuid}")
            ->assertNotFound()
            ->assertJsonPath('message', 'This document is no longer available.');
    }

    #[Test]
    public function reconcile_removes_index_documents_with_no_database_row(): void
    {
        $kept = Document::factory()->count(2)->create();
        $keptIds = $kept->map(fn (Document $d) => str_replace('-', '', $d->uuid))->all();
        $orphan = 'deadbeefdeadbeefdeadbeefdeadbeef';

        $index = Mockery::mock(SearchIndex::class);
        // One short page (< limit) ends the loop: the two known ids plus one orphan.
        $index->shouldReceive('documentIdPage')->once()->andReturn([...$keptIds, $orphan]);
        $index->shouldReceive('deleteByIds')->once()->with([$orphan]);
        $this->instance(SearchIndex::class, $index);

        $this->artisan('nextsearch:reconcile')
            ->expectsOutputToContain('1 orphaned document(s) removed')
            ->assertSuccessful();
    }
}
