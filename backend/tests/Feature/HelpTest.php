<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Document;
use App\Models\Tag;
use App\Models\User;
use App\Models\WatchedFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The help page reads its facts from here. Two things matter: the numbers have
 * to be the caller's own, and the administrator's part must not leak to anyone
 * else — a help page that lists every connected instance would be a directory
 * of the installation for whoever has an account.
 */
class HelpTest extends TestCase
{
    use RefreshDatabase;

    private WatchedFolder $shared;

    private WatchedFolder $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://search.example.org']);

        $this->shared = WatchedFolder::factory()->create(['label' => 'Buchhaltung']);
        $this->foreign = WatchedFolder::factory()->create(['label' => 'Vorstand']);

        Document::factory()->count(3)->for($this->shared, 'folder')->create([
            'nextcloud_instance_id' => $this->shared->nextcloud_instance_id,
            'state' => Document::STATE_INDEXED,
        ]);

        Document::factory()->count(5)->for($this->foreign, 'folder')->create([
            'nextcloud_instance_id' => $this->foreign->nextcloud_instance_id,
            'state' => Document::STATE_INDEXED,
        ]);
    }

    #[Test]
    public function a_user_reads_the_numbers_of_their_own_folders(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->folders()->attach($this->shared);

        $this->actingAs($user)
            ->getJson('/api/help')
            ->assertOk()
            ->assertJsonPath('installation.folders', 1)
            ->assertJsonPath('installation.instances', 1)
            // Three of the eight documents. The other folder does not exist
            // for this user, and neither do its documents.
            ->assertJsonPath('installation.documents', 3)
            ->assertJsonPath('folders.0.label', 'Buchhaltung')
            ->assertJsonCount(1, 'folders')
            ->assertJsonPath('access.is_admin', false)
            ->assertJsonMissingPath('admin');
    }

    #[Test]
    public function an_administrator_reads_the_whole_installation(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->getJson('/api/help')
            ->assertOk()
            ->assertJsonPath('installation.folders', 2)
            ->assertJsonPath('installation.documents', 8)
            ->assertJsonPath('access.is_admin', true)
            ->assertJsonPath('admin.documents_total', 8)
            ->assertJsonPath('admin.admins', 1)
            ->assertJsonCount(2, 'admin.instances');
    }

    #[Test]
    public function the_administrator_sees_invitations_disabled_accounts_and_admin_keys(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        User::factory()->create(['password' => null])->issueInvitation();
        User::factory()->create(['disabled_at' => now()]);
        ApiToken::issue($admin, 'Unterlagen', admin: true);
        ApiToken::issue($admin, 'Suchskript');
        // The flag alone does not count once the owner is no administrator.
        ApiToken::issue(User::factory()->create(['role' => User::ROLE_USER]), 'Alt', admin: true);

        $this->actingAs($admin)
            ->getJson('/api/help')
            ->assertOk()
            ->assertJsonPath('admin.invitations_pending', 1)
            ->assertJsonPath('admin.users_disabled', 1)
            ->assertJsonPath('admin.admin_api_keys', 1)
            ->assertJsonPath('admin.invitation_expire_hours', 168);
    }

    #[Test]
    public function the_vocabulary_is_the_one_this_user_may_use(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $stranger = User::factory()->create();

        Tag::factory()->create(['slug' => 'vertrag']);
        Tag::factory()->adminOnly()->create(['slug' => 'freigegeben']);
        Tag::factory()->ownedBy($stranger)->create(['slug' => 'fremd']);

        $response = $this->actingAs($user)->getJson('/api/help')->assertOk();
        $tags = collect($response->json('tags'));

        $this->assertEqualsCanonicalizing(['vertrag', 'freigegeben'], $tags->pluck('slug')->all());
        $this->assertFalse($tags->firstWhere('slug', 'freigegeben')['assignable']);
        $this->assertTrue($tags->firstWhere('slug', 'vertrag')['assignable']);
    }

    #[Test]
    public function the_page_reports_what_the_installation_actually_does(): void
    {
        config([
            'nextsearch.tika.ocr.enabled' => false,
            'nextsearch.index.extensions' => ['pdf', 'docx'],
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->getJson('/api/help')
            ->assertOk()
            ->assertJsonPath('search.ocr.enabled', false)
            ->assertJsonPath('search.extensions', ['pdf', 'docx'])
            ->assertJsonPath('access.mcp.url', 'https://search.example.org/api/mcp')
            ->assertJsonPath('access.mcp.tools', [
                'search_documents', 'get_document', 'list_tags', 'tag_documents',
            ]);
    }

    #[Test]
    public function a_switched_off_mcp_server_is_reported_as_off(): void
    {
        config(['nextsearch.mcp.enabled' => false]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->getJson('/api/help')
            ->assertOk()
            ->assertJsonPath('access.mcp.enabled', false)
            ->assertJsonPath('access.mcp.url', null)
            ->assertJsonPath('access.mcp.tools', []);
    }

    #[Test]
    public function the_page_needs_a_session(): void
    {
        $this->getJson('/api/help')->assertUnauthorized();
    }
}
