<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\OauthClient;
use App\Models\User;
use App\Services\OAuth\OAuthServer;
use App\Services\OAuth\Scopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Invitations and disabled accounts — the two things an account goes through
 * besides being used.
 */
class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function invitedUser(): array
    {
        $user = User::factory()->create(['password' => null]);
        $issued = $user->issueInvitation();

        return [$user, $issued['token']];
    }

    #[Test]
    public function an_invitation_shows_who_it_is_for(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->getJson("/api/auth/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('invitation.email', $user->email);
    }

    #[Test]
    public function accepting_an_invitation_sets_the_password_signs_in_and_spends_the_link(): void
    {
        [$user, $token] = $this->invitedUser();

        $this->postJson("/api/auth/invitations/{$token}", [
            'password' => 'ein-langes-passwort',
            'password_confirmation' => 'ein-langes-passwort',
        ])->assertOk()->assertJsonPath('signed_in', true);

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->invitation_hash);

        $this->getJson("/api/auth/invitations/{$token}")->assertNotFound();
    }

    #[Test]
    public function an_expired_invitation_is_refused(): void
    {
        [$user, $token] = $this->invitedUser();
        $user->forceFill(['invitation_expires_at' => now()->subMinute()])->save();

        $this->getJson("/api/auth/invitations/{$token}")->assertNotFound();
    }

    #[Test]
    public function a_new_invitation_replaces_the_old_one(): void
    {
        [$user, $old] = $this->invitedUser();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$user->uuid}/invitation")
            ->assertCreated()
            ->assertJsonStructure(['invitation' => ['url', 'expires_at']]);

        auth()->guard('web')->logout();

        $this->getJson("/api/auth/invitations/{$old}")->assertNotFound();
    }

    #[Test]
    public function a_reset_link_does_not_get_around_two_factor(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);
        $token = $user->issueInvitation()['token'];

        $this->postJson("/api/auth/invitations/{$token}", [
            'password' => 'ein-langes-passwort',
            'password_confirmation' => 'ein-langes-passwort',
        ])->assertOk()->assertJsonPath('signed_in', false);

        $this->assertGuest();
    }

    #[Test]
    public function an_account_without_a_password_cannot_sign_in(): void
    {
        [$user] = $this->invitedUser();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => ''])
            ->assertUnprocessable();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'irgendwas-langes'])
            ->assertUnprocessable();

        $this->assertGuest();
    }

    #[Test]
    public function a_disabled_account_cannot_sign_in(): void
    {
        $user = User::factory()->create([
            'password' => 'ein-langes-passwort',
            'disabled_at' => now(),
        ]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'ein-langes-passwort'])
            ->assertForbidden();

        $this->assertGuest();
    }

    #[Test]
    public function disabling_ends_a_running_session_and_stops_keys_and_oauth_tokens(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $plain = ApiToken::issue($user, 'Skript')['plain'];
        $oauthToken = $this->oauthTokenFor($user);

        $this->withToken($plain)->getJson('/api/v1/me')->assertOk();
        $this->assertNotNull(app(OAuthServer::class)->resolveAccessToken($oauthToken));

        $user->forceFill(['disabled_at' => now()])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertNull(app(OAuthServer::class)->resolveAccessToken($oauthToken));

        $this->app['auth']->forgetGuards();
        $this->actingAs($user)->getJson('/api/auth/me')->assertUnauthorized();
    }

    #[Test]
    public function the_admin_api_disables_an_account(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->withToken(ApiToken::issue($admin, 'Unterlagen', admin: true)['plain'])
            ->patchJson("/api/v1/admin/users/{$user->uuid}", ['disabled' => true])
            ->assertOk()
            ->assertJsonPath('user.disabled', true);

        $this->assertTrue($user->fresh()->isDisabled());
    }

    private function oauthTokenFor(User $user): string
    {
        $client = OauthClient::factory()->create();
        $redirect = $client->redirect_uris[0];
        $verifier = str_repeat('v', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $server = app(OAuthServer::class);
        $code = $server->issueAuthorizationCode($client, $user, $redirect, Scopes::all(), $challenge);

        return $server->exchangeAuthorizationCode($client, $code, $redirect, $verifier)['access_token'];
    }

    #[Test]
    public function disabling_twice_keeps_the_first_timestamp_and_enabling_undoes_it(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->putJson("/api/admin/users/{$user->uuid}", ['disabled' => true])->assertOk();
        $first = $user->fresh()->disabled_at;

        $this->travel(5)->minutes();

        $this->actingAs($admin)->putJson("/api/admin/users/{$user->uuid}", ['disabled' => true])->assertOk();
        $this->assertEquals($first, $user->fresh()->disabled_at);

        $this->actingAs($admin)->putJson("/api/admin/users/{$user->uuid}", ['disabled' => false])->assertOk();
        $this->assertNull($user->fresh()->disabled_at);
    }

    #[Test]
    public function the_last_active_administrator_cannot_be_disabled_and_nobody_disables_themselves(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $other = User::factory()->create(['role' => User::ROLE_ADMIN, 'disabled_at' => now()]);

        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$admin->uuid}", ['disabled' => true])
            ->assertUnprocessable();

        // The other administrator is disabled, so demoting the only active
        // one is refused as well.
        $this->actingAs($admin)
            ->putJson("/api/admin/users/{$admin->uuid}", ['role' => User::ROLE_USER])
            ->assertUnprocessable();

        $this->assertTrue($other->fresh()->isDisabled());
    }
}
