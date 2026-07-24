<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['state', 'trigger', 'full', 'started_at', 'finished_at', 'errors', 'pending_jobs'])]
class IndexRun extends Model
{
    use HasUuids;

    public const STATE_RUNNING = 'running';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    /** At most this many errors are kept per run. */
    public const MAX_ERRORS = 50;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'full' => 'boolean',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WatchedFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(WatchedFolder::class, 'watched_folder_id');
    }

    /**
     * Counters increment atomically because several workers write to the same
     * run in parallel.
     */
    public function bump(string $counter, int $by = 1): void
    {
        $this->newQuery()->whereKey($this->getKey())->increment($counter, $by);
    }

    /**
     * Increment before every dispatch — otherwise the counter drops to zero in
     * between and the run is considered finished prematurely.
     */
    public function trackJobs(int $count = 1): void
    {
        $this->bump('pending_jobs', $count);
    }

    /**
     * Decrement after every finished job. The worker whose decrement reaches
     * zero closes the run — thanks to RETURNING exactly one sees the zero, even
     * when several finish at the same time.
     */
    public function settleJob(): void
    {
        $row = DB::selectOne(
            'UPDATE index_runs SET pending_jobs = pending_jobs - 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND pending_jobs > 0 RETURNING pending_jobs',
            [$this->getKey()],
        );

        if ($row !== null && (int) $row->pending_jobs === 0) {
            $this->complete();
        }
    }

    public function complete(): void
    {
        $this->newQuery()
            ->whereKey($this->getKey())
            ->where('state', self::STATE_RUNNING)
            ->update([
                'state' => self::STATE_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $this->markFolderCrawled();
    }

    private function markFolderCrawled(): void
    {
        WatchedFolder::query()
            ->whereKey($this->watched_folder_id)
            ->update(['last_crawled_at' => now(), 'crawl_requested_at' => null]);
    }

    public function recordError(string $path, string $message): void
    {
        $errors = $this->errors ?? [];

        if (count($errors) < self::MAX_ERRORS) {
            $errors[] = ['path' => $path, 'message' => $message];
            $this->errors = $errors;
            $this->save();
        }

        $this->bump('files_failed');
    }
}
