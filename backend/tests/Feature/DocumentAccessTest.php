<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\WatchedFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_document_in_a_shared_folder_is_visible(): void
    {
        $folder = WatchedFolder::factory()->create();
        $document = Document::factory()->for($folder, 'folder')->create([
            'nextcloud_instance_id' => $folder->nextcloud_instance_id,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->folders()->attach($folder);

        $this->actingAs($user)
            ->getJson('/api/documents/'.$document->uuid)
            ->assertOk()
            ->assertJsonPath('document.uuid', $document->uuid);
    }

    #[Test]
    public function a_document_in_an_unshared_folder_stays_out_of_reach(): void
    {
        $folder = WatchedFolder::factory()->create();
        $document = Document::factory()->for($folder, 'folder')->create([
            'nextcloud_instance_id' => $folder->nextcloud_instance_id,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_USER]);

        // Auch mit bekannter UUID kommt niemand an das Dokument oder die Datei.
        $this->actingAs($user)->getJson('/api/documents/'.$document->uuid)->assertForbidden();
        $this->actingAs($user)->get('/api/documents/'.$document->uuid.'/raw')->assertForbidden();
        $this->actingAs($user)->get('/api/documents/'.$document->uuid.'/preview')->assertForbidden();
    }

    #[Test]
    public function an_admin_reaches_every_document(): void
    {
        $document = Document::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->getJson('/api/documents/'.$document->uuid)
            ->assertOk();
    }

    #[Test]
    public function the_admin_area_is_closed_for_ordinary_users(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)->getJson('/api/admin/instances')->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($user)->getJson('/api/admin/status')->assertForbidden();
    }

    #[Test]
    public function without_a_session_nothing_is_reachable(): void
    {
        $document = Document::factory()->create();

        $this->getJson('/api/documents/'.$document->uuid)->assertUnauthorized();
        $this->getJson('/api/search?q=test')->assertUnauthorized();
    }
}
