<?php

namespace Tests\Feature;

use App\Jobs\CrawlFolderJob;
use App\Models\Document;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Search\SearchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IndexMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function clearing_the_index_flushes_it_and_drops_the_documents(): void
    {
        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('flush')->once();
        $this->instance(SearchIndex::class, $index);

        Document::factory()->count(3)->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/index/clear')
            ->assertOk()
            ->assertJsonPath('message', 'Search index cleared.');

        $this->assertSame(0, Document::query()->count());
    }

    #[Test]
    public function rebuilding_flushes_the_index_and_queues_a_full_crawl_per_folder(): void
    {
        Queue::fake();

        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('flush')->once();
        $this->instance(SearchIndex::class, $index);

        $folders = WatchedFolder::factory()->count(2)->create(['enabled' => true]);
        WatchedFolder::factory()->create(['enabled' => false]);
        // Attach documents to an existing folder — a bare Document factory would
        // spin up its own folder and inflate the count.
        Document::factory()->count(5)->for($folders->first(), 'folder')->create([
            'nextcloud_instance_id' => $folders->first()->nextcloud_instance_id,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/index/rebuild')
            ->assertOk()
            ->assertJsonPath('folders', 2);

        $this->assertSame(0, Document::query()->count());
        // One crawl per enabled folder, the disabled one is skipped.
        Queue::assertPushed(CrawlFolderJob::class, 2);
    }

    #[Test]
    public function a_normal_user_may_not_clear_or_rebuild(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)->postJson('/api/admin/index/clear')->assertForbidden();
        $this->actingAs($user)->postJson('/api/admin/index/rebuild')->assertForbidden();
    }
}
