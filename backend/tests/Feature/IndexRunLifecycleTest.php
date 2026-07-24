<?php

namespace Tests\Feature;

use App\Models\IndexRun;
use App\Models\WatchedFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ein Durchlauf gilt als fertig, wenn kein Job mehr offen ist. Bei mehreren
 * Workern darf genau einer den Abschluss auslösen.
 */
class IndexRunLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_run_finishes_only_once_the_last_job_is_done(): void
    {
        $run = $this->makeRun();
        $run->trackJobs(2);

        $this->assertSame(3, $run->refresh()->pending_jobs);

        $run->settleJob();
        $run->settleJob();
        $this->assertSame(IndexRun::STATE_RUNNING, $run->refresh()->state);

        $run->settleJob();
        $this->assertSame(IndexRun::STATE_COMPLETED, $run->refresh()->state);
        $this->assertNotNull($run->finished_at);
    }

    #[Test]
    public function finishing_marks_the_folder_as_crawled(): void
    {
        $folder = WatchedFolder::factory()->create(['crawl_requested_at' => now()]);
        $run = $this->makeRun($folder);

        $run->settleJob();

        $folder->refresh();
        $this->assertNotNull($folder->last_crawled_at);
        $this->assertNull($folder->crawl_requested_at, 'Die manuelle Anforderung ist damit erledigt.');
    }

    #[Test]
    public function settling_past_zero_does_not_reopen_or_double_finish_the_run(): void
    {
        $run = $this->makeRun();

        $run->settleJob();
        $finishedAt = $run->refresh()->finished_at;

        // Ein verspäteter Nachzügler darf den Zähler nicht ins Negative treiben.
        $run->settleJob();
        $run->refresh();

        $this->assertSame(0, $run->pending_jobs);
        $this->assertSame(IndexRun::STATE_COMPLETED, $run->state);
        $this->assertEquals($finishedAt, $run->finished_at);
    }

    #[Test]
    public function a_folder_is_due_when_its_interval_has_passed(): void
    {
        $fresh = WatchedFolder::factory()->make([
            'interval_minutes' => 15,
            'last_crawled_at' => now()->subMinutes(5),
        ]);

        $overdue = WatchedFolder::factory()->make([
            'interval_minutes' => 15,
            'last_crawled_at' => now()->subMinutes(20),
        ]);

        $paused = WatchedFolder::factory()->make([
            'enabled' => false,
            'last_crawled_at' => null,
        ]);

        $this->assertFalse($fresh->isDue());
        $this->assertTrue($overdue->isDue());
        $this->assertFalse($paused->isDue(), 'Ein pausierter Ordner wird nie fällig.');
    }

    private function makeRun(?WatchedFolder $folder = null): IndexRun
    {
        $folder ??= WatchedFolder::factory()->create();

        return $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => 'test',
            'started_at' => now(),
            'pending_jobs' => 1,
        ]);
    }
}
