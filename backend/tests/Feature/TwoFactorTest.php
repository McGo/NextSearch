<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function otp(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    private function confirmedUser(string $password = 'super-secret-123'): array
    {
        $secret = (new Google2FA)->generateSecretKey();
        $user = User::factory()->create(['role' => User::ROLE_USER, 'password' => $password]);
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => ['AAAA1-BBBB1', 'CCCC2-DDDD2'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$user, $secret, $password];
    }

    #[Test]
    public function enrolment_returns_secret_qr_and_recovery_codes_but_is_not_yet_active(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER])->fresh();

        $this->actingAs($user)
            ->postJson('/api/auth/two-factor')
            ->assertOk()
            ->assertJsonStructure(['secret', 'qr', 'recovery_codes'])
            ->assertJsonPath('qr', fn ($qr) => str_starts_with($qr, 'data:image/svg+xml;base64,'));

        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled(), 'not active until confirmed');
    }

    #[Test]
    public function confirming_with_a_valid_code_activates_two_factor(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER])->fresh();
        $secret = json_decode($this->actingAs($user)->postJson('/api/auth/two-factor')->content(), true)['secret'];

        $this->actingAs($user)
            ->postJson('/api/auth/two-factor/confirm', ['code' => $this->otp($secret)])
            ->assertOk();

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function confirming_with_a_wrong_code_is_rejected(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER])->fresh();
        $this->actingAs($user)->postJson('/api/auth/two-factor');

        $this->actingAs($user)
            ->postJson('/api/auth/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function login_holds_at_the_two_factor_step(): void
    {
        [$user, , $password] = $this->confirmedUser();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password])
            ->assertOk()
            ->assertJsonPath('two_factor', true)
            ->assertJsonMissingPath('user');

        $this->assertGuest();
    }

    #[Test]
    public function the_challenge_accepts_a_totp_code_and_signs_in(): void
    {
        [$user, $secret, $password] = $this->confirmedUser();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password]);

        $this->postJson('/api/auth/two-factor-challenge', ['code' => $this->otp($secret)])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function the_challenge_accepts_and_consumes_a_recovery_code(): void
    {
        [$user, , $password] = $this->confirmedUser();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password]);

        $this->postJson('/api/auth/two-factor-challenge', ['recovery_code' => 'AAAA1-BBBB1'])
            ->assertOk();

        $this->assertNotContains('AAAA1-BBBB1', $user->fresh()->two_factor_recovery_codes);
    }

    #[Test]
    public function the_challenge_rejects_a_wrong_code(): void
    {
        [$user, , $password] = $this->confirmedUser();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $password]);

        $this->postJson('/api/auth/two-factor-challenge', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertGuest();
    }

    #[Test]
    public function a_user_without_two_factor_logs_in_directly(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER, 'password' => 'plain-password-123']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'plain-password-123'])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.two_factor_enabled', false);

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function disabling_requires_the_password(): void
    {
        [$user, , $password] = $this->confirmedUser();

        $this->actingAs($user)
            ->deleteJson('/api/auth/two-factor', ['password' => 'wrong'])
            ->assertStatus(422);

        $this->actingAs($user)
            ->deleteJson('/api/auth/two-factor', ['password' => $password])
            ->assertOk();

        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }
}
