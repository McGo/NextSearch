<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueueClearTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_clears_a_known_queue(): void
    {
        // Decoupled from the queue driver: we only assert the endpoint asks the
        // connection to clear the named queue and reports how many it held.
        $connection = Mockery::mock();
        $connection->shouldReceive('clear')->once()->with('process');
        Queue::shouldReceive('size')->with('process')->andReturn(7);
        Queue::shouldReceive('connection')->andReturn($connection);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/queues/process/clear')
            ->assertOk()
            ->assertJsonPath('queue', 'process')
            ->assertJsonPath('removed', 7);
    }

    #[Test]
    public function an_unknown_queue_name_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/queues/default/clear')
            ->assertNotFound();
    }

    #[Test]
    public function a_normal_user_may_not_clear_a_queue(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->postJson('/api/admin/queues/process/clear')
            ->assertForbidden();
    }
}
