<?php

namespace App\Services\Nextcloud;

use App\Exceptions\NextcloudException;
use App\Models\NextcloudInstance;
use Illuminate\Support\Carbon;

class ConnectionTester
{
    public function __construct(private readonly ReadOnlyWebDavClient $dav) {}

    /**
     * Checks the instance and writes the result into the health state.
     *
     * @return array{ok: bool, message: string, folders: int}
     */
    public function test(NextcloudInstance $instance): array
    {
        try {
            $directories = $this->dav->listDirectories($instance);
        } catch (NextcloudException $e) {
            $this->store($instance, NextcloudInstance::HEALTH_FAILED, $e->getMessage());

            return ['ok' => false, 'message' => $e->getMessage(), 'folders' => 0];
        }

        $message = __('nextsearch.connection.ok', [
            'count' => count($directories),
            'user' => $instance->username,
        ]);

        $this->store($instance, NextcloudInstance::HEALTH_OK, $message);

        return ['ok' => true, 'message' => $message, 'folders' => count($directories)];
    }

    private function store(NextcloudInstance $instance, string $state, string $message): void
    {
        $instance->forceFill([
            'health_state' => $state,
            'health_message' => $message,
            'health_checked_at' => Carbon::now(),
        ])->save();
    }
}
