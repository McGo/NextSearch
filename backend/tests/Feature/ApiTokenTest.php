<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Personal API keys. A key carries no permissions of its own — it stands in for
 * its owner, and for nobody else.
 */
class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_key_is_shown_once_and_stored_only_as_a_hash(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $response = $this->actingAs($user)
            ->postJson('/api/auth/tokens', ['name' => 'Skript'])
            ->assertCreated();

        $plain = $response->json('token');

        $this->assertStringStartsWith(ApiToken::PREFIX, $plain);
        $this->assertSame(mb_substr($plain, -4), $response->json('last_four'));

        $this->assertDatabaseHas('api_tokens', ['token_hash' => ApiToken::hash($plain)]);
        $this->assertDatabaseMissing('api_tokens', ['name' => $plain]);

        // The listing never hands it out again.
        $this->actingAs($user)
            ->getJson('/api/auth/tokens')
            ->assertOk()
            ->assertJsonMissingPath('data.0.token');
    }

    #[Test]
    public function a_valid_key_authenticates_a_v1_request(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $issued = ApiToken::issue($user, 'Skript');

        $this->withToken($issued['plain'])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    #[Test]
    public function without_a_key_or_with_a_wrong_one_there_is_no_way_in(): void
    {
        $this->getJson('/api/v1/tags')->assertUnauthorized();
        $this->withToken('nxs_definitivfalsch')->getJson('/api/v1/tags')->assertUnauthorized();
    }

    #[Test]
    public function an_expired_key_stops_working(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $issued = ApiToken::issue($user, 'Abgelaufen', new \DateTimeImmutable('-1 hour'));

        $this->withToken($issued['plain'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[Test]
    public function using_a_key_records_when_it_last_worked(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $issued = ApiToken::issue($user, 'Skript');

        $this->assertNull($issued['token']->last_used_at);

        $this->withToken($issued['plain'])->getJson('/api/v1/me')->assertOk();

        $this->assertNotNull($issued['token']->fresh()->last_used_at);
    }

    #[Test]
    public function a_revoked_key_is_dead_immediately(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $issued = ApiToken::issue($user, 'Skript');

        $this->actingAs($user)
            ->deleteJson('/api/auth/tokens/'.$issued['token']->uuid)
            ->assertNoContent();

        $this->withToken($issued['plain'])->getJson('/api/v1/me')->assertUnauthorized();
    }

    #[Test]
    public function nobody_revokes_someone_elses_key(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $other = User::factory()->create(['role' => User::ROLE_USER]);
        $issued = ApiToken::issue($other, 'Fremd');

        $this->actingAs($user)
            ->deleteJson('/api/auth/tokens/'.$issued['token']->uuid)
            ->assertNotFound();

        $this->assertDatabaseHas('api_tokens', ['id' => $issued['token']->id]);
    }

    #[Test]
    public function the_listing_only_shows_ones_own_keys(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $other = User::factory()->create(['role' => User::ROLE_USER]);

        ApiToken::issue($user, 'Meins');
        ApiToken::issue($other, 'Fremd');

        $this->actingAs($user)
            ->getJson('/api/auth/tokens')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Meins');
    }

    #[Test]
    public function a_key_cannot_mint_another_key(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $issued = ApiToken::issue($user, 'Skript');

        // Key management runs over the session only.
        $this->withToken($issued['plain'])
            ->postJson('/api/auth/tokens', ['name' => 'Noch einer'])
            ->assertUnauthorized();
    }
}
