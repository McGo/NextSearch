<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Directory\BrandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    private function disk(): string
    {
        return (string) config('nextsearch.preview.disk');
    }

    #[Test]
    public function an_admin_uploads_a_logo_and_it_drives_every_asset(): void
    {
        Storage::fake($this->disk());
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/admin/branding/logo', [
                'image' => File::image('logo.png', 320, 120),
            ])
            ->assertOk()
            ->assertJsonPath('has_logo', true);

        // Header logo plus the three square icons all exist.
        Storage::disk($this->disk())->assertExists(BrandingService::LOGO);
        Storage::disk($this->disk())->assertExists(BrandingService::ICON_192);
        Storage::disk($this->disk())->assertExists(BrandingService::ICON_512);
        Storage::disk($this->disk())->assertExists(BrandingService::ICON_MASKABLE);
    }

    #[Test]
    public function the_branding_state_is_public(): void
    {
        Storage::fake($this->disk());

        $this->getJson('/api/branding')
            ->assertOk()
            ->assertJsonPath('has_logo', false)
            ->assertJsonPath('logo_url', null);
    }

    #[Test]
    public function an_icon_falls_back_to_the_bundled_default_without_a_logo(): void
    {
        Storage::fake($this->disk());

        $this->get('/api/branding/icon/192')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    #[Test]
    public function an_unknown_icon_variant_is_rejected(): void
    {
        Storage::fake($this->disk());

        $this->get('/api/branding/icon/nonsense')->assertNotFound();
    }

    #[Test]
    public function a_normal_user_may_not_upload_a_logo(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->postJson('/api/admin/branding/logo', ['image' => File::image('logo.png')])
            ->assertForbidden();
    }

    #[Test]
    public function deleting_the_logo_clears_the_assets(): void
    {
        Storage::fake($this->disk());
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->postJson('/api/admin/branding/logo', [
            'image' => File::image('logo.png', 320, 120),
        ])->assertOk();

        $this->actingAs($admin)
            ->deleteJson('/api/admin/branding/logo')
            ->assertOk()
            ->assertJsonPath('has_logo', false);

        Storage::disk($this->disk())->assertMissing(BrandingService::LOGO);
    }
}
