<?php

namespace Tests\Feature;

use App\Models\NextcloudInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstanceManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_app_password_is_stored_encrypted_and_never_returned(): void
    {
        $instance = NextcloudInstance::factory()->create(['app_password' => 'geheim-123']);

        $stored = DB::table('nextcloud_instances')->where('id', $instance->id)->value('app_password');

        $this->assertNotSame('geheim-123', $stored, 'Das Passwort darf nicht im Klartext in der Datenbank stehen.');
        $this->assertSame('geheim-123', Crypt::decryptString($stored));
        $this->assertSame('geheim-123', $instance->fresh()->app_password);

        // Auch nicht über die API.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->getJson('/api/admin/instances')
            ->assertOk()
            ->assertJsonMissing(['app_password' => 'geheim-123']);
    }

    #[Test]
    public function an_empty_password_field_keeps_the_stored_one(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $instance = NextcloudInstance::factory()->create(['app_password' => 'urspruenglich']);

        $this->actingAs($admin)
            ->putJson('/api/admin/instances/'.$instance->uuid, [
                'name' => 'Neuer Name',
                'app_password' => '',
            ])
            ->assertOk();

        $instance->refresh();

        $this->assertSame('Neuer Name', $instance->name);
        $this->assertSame('urspruenglich', $instance->app_password);
    }

    #[Test]
    public function the_dav_root_is_built_from_base_url_and_user(): void
    {
        $instance = NextcloudInstance::factory()->make([
            'base_url' => 'https://cloud.example.de/nextcloud/',
            'username' => 'indexer konto',
        ]);

        $this->assertSame(
            'https://cloud.example.de/nextcloud/remote.php/dav/files/indexer%20konto',
            $instance->davRoot(),
        );
    }
}
