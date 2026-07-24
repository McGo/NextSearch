<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\IndexRun;
use App\Models\User;
use App\Models\WatchedFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IndexingStatusTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reports_running_with_the_pending_count(): void
    {
        $folder = WatchedFolder::factory()->create();
        $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => 'test',
            'started_at' => now(),
            'pending_jobs' => 7,
        ]);
        Document::factory()->count(3)->create(['state' => Document::STATE_INDEXED]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_USER]))
            ->getJson('/api/indexing-status')
            ->assertOk()
            ->assertJson(['running' => true, 'pending' => 7, 'indexed' => 3]);
    }

    #[Test]
    public function it_reports_idle_when_nothing_runs(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_USER]))
            ->getJson('/api/indexing-status')
            ->assertOk()
            ->assertJson(['running' => false, 'pending' => 0]);
    }

    #[Test]
    public function it_needs_a_session(): void
    {
        $this->getJson('/api/indexing-status')->assertUnauthorized();
    }
}
