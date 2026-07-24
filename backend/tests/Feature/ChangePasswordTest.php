<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'role' => User::ROLE_USER,
            'password' => 'altes-passwort-123',
        ]);
    }

    #[Test]
    public function a_user_changes_their_own_password(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->putJson('/api/auth/password', [
                'current_password' => 'altes-passwort-123',
                'password' => 'ganz-neues-passwort',
                'password_confirmation' => 'ganz-neues-passwort',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Passwort geändert.');

        $this->assertTrue(Hash::check('ganz-neues-passwort', $user->fresh()->password));
    }

    #[Test]
    public function the_current_password_must_be_correct(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->putJson('/api/auth/password', [
                'current_password' => 'falsch',
                'password' => 'ganz-neues-passwort',
                'password_confirmation' => 'ganz-neues-passwort',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('altes-passwort-123', $user->fresh()->password));
    }

    #[Test]
    public function the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->putJson('/api/auth/password', [
                'current_password' => 'altes-passwort-123',
                'password' => 'kurz',
                'password_confirmation' => 'anders',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    #[Test]
    public function the_new_password_must_differ_from_the_current_one(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->putJson('/api/auth/password', [
                'current_password' => 'altes-passwort-123',
                'password' => 'altes-passwort-123',
                'password_confirmation' => 'altes-passwort-123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    #[Test]
    public function changing_the_password_needs_a_session(): void
    {
        $this->putJson('/api/auth/password', [
            'current_password' => 'x',
            'password' => 'ganz-neues-passwort',
            'password_confirmation' => 'ganz-neues-passwort',
        ])->assertUnauthorized();
    }
}
