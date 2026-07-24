<?php

namespace Tests\Feature;

use App\Models\NextcloudInstance;
use App\Models\User;
use App\Models\WatchedFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DirectoryImageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    #[Test]
    public function an_admin_uploads_an_instance_image_and_it_becomes_servable(): void
    {
        Storage::fake('s3');
        $instance = NextcloudInstance::factory()->create();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/instances/'.$instance->uuid.'/image', [
                'image' => UploadedFile::fake()->image('logo.png', 300, 200),
            ])
            ->assertOk()
            ->assertJsonPath('instance.image_url', '/api/instances/'.$instance->uuid.'/image');

        $instance->refresh();
        $this->assertNotNull($instance->image_key);
        Storage::disk('s3')->assertExists($instance->image_key);

        // The image is retrievable afterwards — even for a normal user.
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->get('/api/instances/'.$instance->uuid.'/image')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }

    #[Test]
    public function a_folder_image_upload_and_removal_round_trips(): void
    {
        Storage::fake('s3');
        $folder = WatchedFolder::factory()->create();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/folders/'.$folder->uuid.'/image', [
                'image' => UploadedFile::fake()->image('folder.jpg'),
            ])
            ->assertOk();

        $key = $folder->refresh()->image_key;
        $this->assertNotNull($key);
        Storage::disk('s3')->assertExists($key);

        $this->actingAs($this->admin())
            ->deleteJson('/api/admin/folders/'.$folder->uuid.'/image')
            ->assertOk()
            ->assertJsonPath('folder.image_url', null);

        $this->assertNull($folder->refresh()->image_key);
        Storage::disk('s3')->assertMissing($key);
    }

    #[Test]
    public function a_non_image_upload_is_rejected(): void
    {
        Storage::fake('s3');
        $instance = NextcloudInstance::factory()->create();

        $this->actingAs($this->admin())
            ->postJson('/api/admin/instances/'.$instance->uuid.'/image', [
                'image' => UploadedFile::fake()->create('schad.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_regular_user_cannot_upload(): void
    {
        $instance = NextcloudInstance::factory()->create();
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->postJson('/api/admin/instances/'.$instance->uuid.'/image', [
                'image' => UploadedFile::fake()->image('x.png'),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function the_directory_lists_only_what_a_user_may_see(): void
    {
        $mine = WatchedFolder::factory()->create(['label' => 'Meiner']);
        WatchedFolder::factory()->create(['label' => 'Fremder']);

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->folders()->attach($mine);

        $response = $this->actingAs($user)->getJson('/api/directory')->assertOk();

        $labels = collect($response->json('folders'))->pluck('label');
        $this->assertContains('Meiner', $labels);
        $this->assertNotContains('Fremder', $labels);
    }

    #[Test]
    public function an_image_that_was_never_set_returns_404(): void
    {
        $instance = NextcloudInstance::factory()->create();

        $this->actingAs($this->admin())
            ->get('/api/instances/'.$instance->uuid.'/image')
            ->assertNotFound();
    }
}
