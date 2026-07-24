<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bootstrap runs on every start of the app container. It must not overwrite
 * an existing account in the process.
 */
class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_the_admin_from_the_environment(): void
    {
        config([
            'nextsearch.admin.email' => 'chef@example.de',
            'nextsearch.admin.password' => 'ein-langes-passwort',
            'nextsearch.admin.name' => 'Chefin',
        ]);

        $this->artisan('nextsearch:bootstrap')->assertSuccessful();

        $admin = User::query()->where('email', 'chef@example.de')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check('ein-langes-passwort', $admin->password));
    }

    #[Test]
    public function running_it_again_leaves_an_existing_account_alone(): void
    {
        config([
            'nextsearch.admin.email' => 'chef@example.de',
            'nextsearch.admin.password' => 'neues-passwort-aus-env',
        ]);

        $existing = User::factory()->create([
            'email' => 'chef@example.de',
            'password' => 'selbst-gesetztes-passwort',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->artisan('nextsearch:bootstrap')->assertSuccessful();

        $existing->refresh();

        $this->assertTrue(
            Hash::check('selbst-gesetztes-passwort', $existing->password),
            'The bootstrap must not reset a password changed in the interface.',
        );
        $this->assertSame(1, User::query()->where('email', 'chef@example.de')->count());
    }

    #[Test]
    public function without_credentials_it_simply_creates_nothing(): void
    {
        config(['nextsearch.admin.email' => null, 'nextsearch.admin.password' => null]);

        $this->artisan('nextsearch:bootstrap')->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }
}
