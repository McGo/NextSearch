<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\IndexRun;
use App\Models\User;
use App\Models\WatchedFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IndexingStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_running_when_jobs_are_queued_with_the_pending_document_count(): void
    {
        Queue::shouldReceive('size')->andReturnUsing(fn ($queue) => $queue === 'process' ? 5 : 0);

        Document::factory()->count(2)->create(['state' => Document::STATE_PENDING]);
        Document::factory()->count(3)->create(['state' => Document::STATE_INDEXED]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_USER]))
            ->getJson('/api/indexing-status')
            ->assertOk()
            ->assertJson(['running' => true, 'pending' => 2, 'indexed' => 3]);
    }

    #[Test]
    public function it_reports_idle_when_no_jobs_are_queued(): void
    {
        Queue::shouldReceive('size')->andReturn(0);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_USER]))
            ->getJson('/api/indexing-status')
            ->assertOk()
            ->assertJson(['running' => false, 'pending' => 0]);
    }

    #[Test]
    public function a_stuck_running_run_does_not_keep_it_reported_as_running(): void
    {
        // The queues are empty, but a run was interrupted and still shows
        // pending_jobs > 0. That must not report as running — the search banner
        // would otherwise never clear.
        Queue::shouldReceive('size')->andReturn(0);

        $folder = WatchedFolder::factory()->create();
        $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => 'test',
            'started_at' => now(),
            'pending_jobs' => 7,
        ]);
        Document::factory()->count(4)->create(['state' => Document::STATE_PENDING]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_USER]))
            ->getJson('/api/indexing-status')
            ->assertOk()
            ->assertJson(['running' => false, 'pending' => 4]);
    }

    #[Test]
    public function it_needs_a_session(): void
    {
        $this->getJson('/api/indexing-status')->assertUnauthorized();
    }
}
