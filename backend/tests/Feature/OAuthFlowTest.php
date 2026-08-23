<?php

namespace Tests\Feature;

use App\Models\OauthAccessToken;
use App\Models\OauthClient;
use App\Models\OauthRefreshToken;
use App\Models\User;
use App\Services\OAuth\Scopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The browser flow an MCP client walks to get a token: register itself, send
 * the user to the consent screen, trade the code, keep the connection alive.
 *
 * The interesting cases are the ones that must not work — a callback that was
 * never registered, a verifier that does not match, a code used twice.
 */
class OAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFIER = 'a-verifier-long-enough-to-be-worth-something-0123456789';

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://search.example.org']);

        $this->user = User::factory()->create(['role' => User::ROLE_USER]);
    }

    private function challenge(string $verifier = self::VERIFIER): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, string>
     */
    private function authorizeQuery(OauthClient $client, array $overrides = []): array
    {
        return array_merge([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => $this->challenge(),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
            'scope' => Scopes::toString(Scopes::all()),
            'resource' => 'https://search.example.org/api/mcp',
        ], $overrides);
    }

    /** Walks the flow to the point where a code has been issued. */
    private function codeFor(OauthClient $client, ?array $scopes = null): string
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/oauth/authorize?'.http_build_query($this->authorizeQuery($client)), [
                'scopes' => $scopes ?? Scopes::all(),
            ])
            ->assertOk();

        parse_str((string) parse_url($response->json('redirect_to'), PHP_URL_QUERY), $query);

        return $query['code'];
    }

    #[Test]
    public function the_discovery_documents_describe_this_installation(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/api/mcp')
            ->assertOk()
            ->assertJsonPath('resource', 'https://search.example.org/api/mcp')
            ->assertJsonPath('authorization_servers.0', 'https://search.example.org');

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', 'https://search.example.org')
            ->assertJsonPath('authorization_endpoint', 'https://search.example.org/oauth/authorize')
            ->assertJsonPath('token_endpoint', 'https://search.example.org/api/oauth/token')
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token']);

        // The same document under the OpenID name, for clients that look there.
        $this->getJson('/.well-known/openid-configuration')->assertOk();
    }

    #[Test]
    public function a_client_registers_itself_and_gets_an_id(): void
    {
        $response = $this->postJson('/api/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT],
        ])->assertCreated();

        $this->assertNotEmpty($response->json('client_id'));
        // A public client gets no secret — PKCE is what it proves itself with.
        $this->assertNull($response->json('client_secret'));
        $this->assertDatabaseHas('oauth_clients', ['name' => 'Claude']);
    }

    #[Test]
    public function a_callback_that_is_neither_https_nor_localhost_is_refused(): void
    {
        $this->postJson('/api/oauth/register', [
            'client_name' => 'Dubios',
            'redirect_uris' => ['http://example.org/callback'],
        ])->assertStatus(422);

        // A desktop client on the loopback interface is fine.
        $this->postJson('/api/oauth/register', [
            'client_name' => 'Desktop',
            'redirect_uris' => ['http://127.0.0.1:33418/callback'],
        ])->assertCreated();
    }

    #[Test]
    public function registration_can_be_switched_off(): void
    {
        config(['nextsearch.mcp.oauth.dynamic_registration' => false]);

        $this->postJson('/api/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => [self::REDIRECT],
        ])->assertForbidden();

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonMissingPath('registration_endpoint');
    }

    #[Test]
    public function the_consent_screen_needs_a_signed_in_user(): void
    {
        $client = OauthClient::factory()->create();

        $this->getJson('/api/oauth/authorize?'.http_build_query($this->authorizeQuery($client)))
            ->assertUnauthorized();
    }

    #[Test]
    public function the_consent_screen_says_who_is_asking_for_what(): void
    {
        $client = OauthClient::factory()->create();

        $this->actingAs($this->user)
            ->getJson('/api/oauth/authorize?'.http_build_query($this->authorizeQuery($client)))
            ->assertOk()
            ->assertJsonPath('client.name', 'Claude Desktop')
            ->assertJsonPath('client.redirect_host', 'claude.ai')
            ->assertJsonPath('client.known', false)
            ->assertJsonPath('scopes.0.id', Scopes::DOCUMENTS_READ)
            ->assertJsonPath('scopes.0.required', true)
            ->assertJsonPath('scopes.1.required', false);
    }

    #[Test]
    public function an_unregistered_callback_stops_on_the_page_rather_than_redirecting(): void
    {
        $client = OauthClient::factory()->create();

        // This is the one case where redirecting would hand a code to whoever
        // asked for it. Nothing goes back to the client.
        $this->actingAs($this->user)
            ->getJson('/api/oauth/authorize?'.http_build_query(
                $this->authorizeQuery($client, ['redirect_uri' => 'https://evil.example.org/cb'])
            ))
            ->assertStatus(422)
            ->assertJsonMissingPath('redirect_to');

        $this->actingAs($this->user)
            ->getJson('/api/oauth/authorize?'.http_build_query(
                $this->authorizeQuery($client, ['client_id' => 'nxc_unknown'])
            ))
            ->assertStatus(422);
    }

    #[Test]
    public function a_desktop_client_may_come_back_on_a_different_port(): void
    {
        // RFC 8252: a client on the loopback interface takes whatever port is
        // free at startup. Pinning it would break the connection on the next
        // launch, and the port is not what makes the callback trustworthy.
        $client = OauthClient::factory()->create([
            'redirect_uris' => ['http://127.0.0.1:33418/callback'],
        ]);

        $this->actingAs($this->user)
            ->getJson('/api/oauth/authorize?'.http_build_query(
                $this->authorizeQuery($client, ['redirect_uri' => 'http://127.0.0.1:51234/callback'])
            ))
            ->assertOk()
            ->assertJsonPath('client.name', 'Claude Desktop');

        // The path still has to match, and a different host is a different
        // address whatever the port says.
        $this->actingAs($this->user)
            ->getJson('/api/oauth/authorize?'.http_build_query(
                $this->authorizeQuery($client, ['redirect_uri' => 'http://127.0.0.1:51234/somewhere-else'])
            ))
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->getJson('/api/oauth/authorize?'.http_build_query(
                $this->authorizeQuery($client, ['redirect_uri' => 'http://evil.example.org:51234/callback'])
            ))
            ->assertStatus(422);
    }

    #[Test]
    public function a_request_without_pkce_is_reported_at_the_callback(): void
    {
        $client = OauthClient::factory()->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/oauth/authorize?'.http_build_query(
                $this->authorizeQuery($client, ['code_challenge' => '', 'code_challenge_method' => ''])
            ))
            ->assertOk();

        $this->assertStringStartsWith(self::REDIRECT.'?error=invalid_request', $response->json('redirect_to'));
        $this->assertStringContainsString('state=xyz', $response->json('redirect_to'));
    }

    #[Test]
    public function a_full_flow_ends_in_a_working_token(): void
    {
        $client = OauthClient::factory()->create();
        $code = $this->codeFor($client);

        $token = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ])->assertOk();

        $this->assertSame('Bearer', $token->json('token_type'));
        $this->assertSame(3600, $token->json('expires_in'));
        $this->assertNotEmpty($token->json('refresh_token'));
        $this->assertSame(Scopes::toString(Scopes::all()), $token->json('scope'));

        // Tokens have no business in a cache.
        $token->assertHeader('Cache-Control', 'no-store, private');

        // And the thing actually opens the endpoint it was issued for.
        $this->withToken($token->json('access_token'))
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertOk()
            ->assertJsonPath('id', 1);
    }

    #[Test]
    public function the_user_can_withhold_the_writing_permission(): void
    {
        $client = OauthClient::factory()->create();
        $code = $this->codeFor($client, scopes: [Scopes::DOCUMENTS_READ]);

        $token = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ])->assertOk();

        $this->assertSame(Scopes::DOCUMENTS_READ, $token->json('scope'));
    }

    #[Test]
    public function a_wrong_verifier_gets_nothing(): void
    {
        $client = OauthClient::factory()->create();
        $code = $this->codeFor($client);

        $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => 'something-else-entirely',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');
    }

    #[Test]
    public function a_code_used_twice_takes_the_first_token_down_with_it(): void
    {
        $client = OauthClient::factory()->create();
        $code = $this->codeFor($client);

        $payload = [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ];

        $first = $this->postJson('/api/oauth/token', $payload)->assertOk();

        $this->postJson('/api/oauth/token', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_grant');

        // Either the client is broken or someone else had the code. Neither
        // case should leave a working token behind.
        $this->withToken($first->json('access_token'))
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_refresh_rotates_both_tokens(): void
    {
        $client = OauthClient::factory()->create();
        $first = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $this->codeFor($client),
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ])->assertOk();

        $second = $this->postJson('/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->client_id,
            'refresh_token' => $first->json('refresh_token'),
        ])->assertOk();

        $this->assertNotSame($first->json('access_token'), $second->json('access_token'));
        $this->assertNotSame($first->json('refresh_token'), $second->json('refresh_token'));

        // The old access token is spent along with the refresh token it came in
        // with, and using the old refresh token again ends the connection.
        $this->withToken($first->json('access_token'))
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized();

        $this->postJson('/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $client->client_id,
            'refresh_token' => $first->json('refresh_token'),
        ])->assertStatus(400);

        $this->withToken($second->json('access_token'))
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized();
    }

    #[Test]
    public function a_client_can_hand_its_token_back(): void
    {
        $client = OauthClient::factory()->create();
        $token = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $this->codeFor($client),
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ])->assertOk();

        $this->postJson('/api/oauth/revoke', [
            'client_id' => $client->client_id,
            'token' => $token->json('access_token'),
        ])->assertOk();

        $this->withToken($token->json('access_token'))
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized();

        // An unknown token is answered the same way — the endpoint must not
        // turn into a way of testing strings for validity.
        $this->postJson('/api/oauth/revoke', [
            'client_id' => $client->client_id,
            'token' => 'nxo_nonsense',
        ])->assertOk();
    }

    #[Test]
    public function a_token_belonging_to_another_client_is_refused(): void
    {
        $client = OauthClient::factory()->create();
        $other = OauthClient::factory()->create(['name' => 'Fremd']);

        $token = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $this->codeFor($client),
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ])->assertOk();

        $this->postJson('/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $other->client_id,
            'refresh_token' => $token->json('refresh_token'),
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    #[Test]
    public function a_confidential_client_needs_its_secret(): void
    {
        $client = OauthClient::factory()->confidential('geheim')->create();
        $code = $this->codeFor($client);

        $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ])->assertStatus(401)->assertJsonPath('error', 'invalid_client');

        $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_secret' => 'geheim',
            'client_id' => $client->client_id,
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ])->assertOk();
    }

    #[Test]
    public function declining_sends_the_client_away_empty_handed(): void
    {
        $client = OauthClient::factory()->create();

        $response = $this->actingAs($this->user)
            ->postJson('/api/oauth/authorize/deny?'.http_build_query($this->authorizeQuery($client)))
            ->assertOk();

        $this->assertStringContainsString('error=access_denied', $response->json('redirect_to'));
        $this->assertDatabaseCount('oauth_authorization_codes', 0);
    }

    #[Test]
    public function the_profile_lists_the_connections_and_can_cut_one(): void
    {
        $client = OauthClient::factory()->create();
        $token = $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $this->codeFor($client),
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ])->assertOk();

        $this->actingAs($this->user)
            ->getJson('/api/oauth/connections')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Claude Desktop')
            ->assertJsonPath('data.0.scopes', Scopes::all());

        $this->actingAs($this->user)
            ->deleteJson('/api/oauth/connections/'.$client->client_id)
            ->assertNoContent();

        $this->withToken($token->json('access_token'))
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertUnauthorized();

        $this->actingAs($this->user)
            ->getJson('/api/oauth/connections')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function one_user_cannot_revoke_another_ones_connection(): void
    {
        $client = OauthClient::factory()->create();
        $this->postJson('/api/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->client_id,
            'code' => $this->codeFor($client),
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => self::VERIFIER,
        ])->assertOk();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->deleteJson('/api/oauth/connections/'.$client->client_id)
            ->assertNoContent();

        // Nothing of the owner's was touched.
        $this->assertSame(1, OauthAccessToken::query()->whereNull('revoked_at')->count());
        $this->assertSame(1, OauthRefreshToken::query()->whereNull('revoked_at')->count());
    }
}
