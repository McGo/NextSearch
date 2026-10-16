<?php

namespace Tests\Feature;

use App\Jobs\CrawlFolderJob;
use App\Models\ApiToken;
use App\Models\Document;
use App\Models\IndexRun;
use App\Models\NextcloudInstance;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Nextcloud\ReadOnlyWebDavClient;
use App\Services\Nextcloud\RemoteEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin API under /api/v1/admin — what another system needs to keep
 * accounts, folders and shares in step with itself.
 */
class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function adminKey(?User $owner = null): string
    {
        $owner ??= User::factory()->create(['role' => User::ROLE_ADMIN]);

        return ApiToken::issue($owner, 'Unterlagen', admin: true)['plain'];
    }

    private function fakeEntry(string $path, bool $isDirectory): RemoteEntry
    {
        return new RemoteEntry(
            path: $path,
            name: basename($path),
            isDirectory: $isDirectory,
            fileId: '4711',
            etag: '"e"',
            size: $isDirectory ? 0 : 1000,
            contentType: $isDirectory ? null : 'application/pdf',
            modifiedAt: null,
        );
    }

    #[Test]
    public function an_ordinary_key_of_an_administrator_does_not_open_the_admin_api(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $plain = ApiToken::issue($admin, 'Suchskript')['plain'];

        $this->withToken($plain)->getJson('/api/v1/admin/users')->assertForbidden();
    }

    #[Test]
    public function an_admin_key_stops_working_when_its_owner_loses_the_role(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $plain = $this->adminKey($owner);

        $this->withToken($plain)->getJson('/api/v1/admin/users')->assertOk();

        $owner->forceFill(['role' => User::ROLE_USER])->save();

        $this->withToken($plain)->getJson('/api/v1/admin/users')->assertForbidden();
    }

    #[Test]
    public function only_an_administrator_can_make_an_admin_key(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($user)
            ->postJson('/api/auth/tokens', ['name' => 'Versuch', 'admin' => true])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson('/api/auth/tokens', ['name' => 'Unterlagen', 'admin' => true])
            ->assertCreated()
            ->assertJsonPath('admin', true);
    }

    #[Test]
    public function creating_an_account_without_a_password_returns_an_invitation_link(): void
    {
        $folder = WatchedFolder::factory()->create();

        $response = $this->withToken($this->adminKey())
            ->postJson('/api/v1/admin/users', [
                'name' => 'Oma Erna',
                'email' => 'erna@example.org',
                'folders' => [$folder->uuid],
            ])
            ->assertCreated()
            ->assertJsonPath('user.has_password', false)
            ->assertJsonPath('user.invitation_pending', true)
            ->assertJsonPath('user.folders.0.uuid', $folder->uuid);

        $url = $response->json('invitation.url');
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/invite/', $url);

        // Only the hash is kept.
        $token = basename($url);
        $this->assertDatabaseMissing('users', ['invitation_hash' => $token]);
        $this->assertDatabaseHas('users', ['invitation_hash' => User::hashInvitation($token)]);
    }

    #[Test]
    public function an_account_can_be_found_by_its_address(): void
    {
        User::factory()->create(['email' => 'erna@example.org']);
        User::factory()->create(['email' => 'karl@example.org']);

        $this->withToken($this->adminKey())
            ->getJson('/api/v1/admin/users?email=Erna@Example.org')
            ->assertOk()
            ->assertJsonCount(1, 'users')
            ->assertJsonPath('users.0.email', 'erna@example.org');
    }

    #[Test]
    public function setting_the_shares_twice_leaves_exactly_those_folders(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        [$a, $b, $c] = WatchedFolder::factory()->count(3)->create();
        $user->folders()->sync([$a->id, $c->id]);
        $key = $this->adminKey();

        foreach ([1, 2] as $attempt) {
            $this->withToken($key)
                ->putJson("/api/v1/admin/users/{$user->uuid}/folders", ['folders' => [$b->uuid]])
                ->assertOk()
                ->assertJsonCount(1, 'user.folders')
                ->assertJsonPath('user.folders.0.uuid', $b->uuid);
        }
    }

    #[Test]
    public function the_api_cannot_delete_accounts(): void
    {
        $user = User::factory()->create();

        $this->withToken($this->adminKey())
            ->deleteJson("/api/v1/admin/users/{$user->uuid}")
            ->assertStatus(405);
    }

    #[Test]
    public function a_folder_that_is_already_watched_comes_back_with_409_and_its_uuid(): void
    {
        $instance = NextcloudInstance::factory()->create();
        $folder = WatchedFolder::factory()->create([
            'nextcloud_instance_id' => $instance->id,
            'remote_path' => 'Familie/Unterlagen/Erna',
        ]);

        $this->withToken($this->adminKey())
            ->postJson('/api/v1/admin/folders', [
                'instance' => $instance->uuid,
                'label' => 'Erna',
                'remote_path' => '/Familie/Unterlagen/Erna/',
            ])
            ->assertStatus(409)
            ->assertJsonPath('folder.uuid', $folder->uuid);
    }

    #[Test]
    public function a_new_folder_is_checked_against_nextcloud_and_created(): void
    {
        $instance = NextcloudInstance::factory()->create();

        $this->mock(ReadOnlyWebDavClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('stat')->once()->andReturn($this->fakeEntry('Familie/Unterlagen/Karl', true));
        });

        $this->withToken($this->adminKey())
            ->postJson('/api/v1/admin/folders', [
                'instance' => $instance->uuid,
                'label' => 'Karl',
                'remote_path' => 'Familie/Unterlagen/Karl',
            ])
            ->assertCreated()
            ->assertJsonPath('folder.label', 'Karl')
            ->assertJsonPath('folder.instance.uuid', $instance->uuid);
    }

    #[Test]
    public function reindexing_a_file_path_crawls_only_its_directory(): void
    {
        Queue::fake();
        $folder = WatchedFolder::factory()->create(['remote_path' => 'Familie/Erna']);

        $this->mock(ReadOnlyWebDavClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('stat')
                ->once()
                ->with(\Mockery::any(), 'Familie/Erna/2026/rente.pdf')
                ->andReturn($this->fakeEntry('Familie/Erna/2026/rente.pdf', false));
        });

        $this->withToken($this->adminKey())
            ->postJson("/api/v1/admin/folders/{$folder->uuid}/reindex", ['path' => '2026/rente.pdf'])
            ->assertOk()
            ->assertJsonPath('run.path', '2026');

        Queue::assertPushed(CrawlFolderJob::class, fn (CrawlFolderJob $job) => $job->subPath === '2026');
        $this->assertSame('2026', IndexRun::query()->sole()->sub_path);
    }

    #[Test]
    public function a_sub_path_joins_a_run_that_is_already_going(): void
    {
        Queue::fake();
        $folder = WatchedFolder::factory()->create(['remote_path' => 'Familie/Erna']);
        $running = $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => 'schedule',
            'full' => false,
            'started_at' => now(),
            'pending_jobs' => 3,
        ]);

        $this->mock(ReadOnlyWebDavClient::class, function (MockInterface $mock) {
            $mock->shouldReceive('stat')->andReturn($this->fakeEntry('Familie/Erna/2026', true));
        });

        $this->withToken($this->adminKey())
            ->postJson("/api/v1/admin/folders/{$folder->uuid}/reindex", ['path' => '2026'])
            ->assertOk()
            ->assertJsonPath('run.uuid', $running->uuid);

        $this->assertSame(4, $running->fresh()->pending_jobs);
        Queue::assertPushed(CrawlFolderJob::class, fn (CrawlFolderJob $job) => $job->run->is($running) && $job->subPath === '2026');
    }

    #[Test]
    public function a_run_over_one_directory_does_not_count_as_a_crawl_of_the_folder(): void
    {
        $folder = WatchedFolder::factory()->create(['last_crawled_at' => null]);
        $run = $folder->runs()->create([
            'state' => IndexRun::STATE_RUNNING,
            'trigger' => 'manual',
            'full' => false,
            'sub_path' => '2026',
            'started_at' => now(),
            'pending_jobs' => 1,
        ]);

        $run->complete();

        $this->assertSame(IndexRun::STATE_COMPLETED, $run->fresh()->state);
        $this->assertNull($folder->fresh()->last_crawled_at);
        $this->assertNull($folder->fresh()->baseline_at);
    }

    #[Test]
    public function climbing_out_of_the_folder_is_refused(): void
    {
        $folder = WatchedFolder::factory()->create();

        $this->withToken($this->adminKey())
            ->postJson("/api/v1/admin/folders/{$folder->uuid}/reindex", ['path' => '../Privat'])
            ->assertUnprocessable();
    }

    #[Test]
    public function a_document_is_found_by_its_path_with_state_and_link(): void
    {
        $folder = WatchedFolder::factory()->create(['remote_path' => 'Familie/Erna']);
        $document = Document::factory()->create([
            'watched_folder_id' => $folder->id,
            'remote_path' => 'Familie/Erna/2026/rente.pdf',
            'path_hash' => Document::hashPath('Familie/Erna/2026/rente.pdf'),
            'state' => Document::STATE_INDEXED,
        ]);

        $this->withToken($this->adminKey())
            ->getJson('/api/v1/admin/documents?'.http_build_query(['folder' => $folder->uuid, 'path' => '/2026/rente.pdf']))
            ->assertOk()
            ->assertJsonPath('document.uuid', $document->uuid)
            ->assertJsonPath('document.path', '2026/rente.pdf')
            ->assertJsonPath('document.state', 'indexed')
            ->assertJsonPath('document.url', rtrim(config('app.url'), '/').'/documents/'.$document->uuid);
    }

    #[Test]
    public function an_unknown_path_is_a_404(): void
    {
        $folder = WatchedFolder::factory()->create();

        $this->withToken($this->adminKey())
            ->getJson('/api/v1/admin/documents?'.http_build_query(['folder' => $folder->uuid, 'path' => 'gibt-es-nicht.pdf']))
            ->assertNotFound();
    }
}
