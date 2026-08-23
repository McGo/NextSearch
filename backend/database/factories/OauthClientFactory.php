<?php

namespace Database\Factories;

use App\Models\OauthClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OauthClient>
 */
class OauthClientFactory extends Factory
{
    protected $model = OauthClient::class;

    public function definition(): array
    {
        return [
            'client_id' => OauthClient::newClientId(),
            'name' => 'Claude Desktop',
            'secret_hash' => null,
            'token_endpoint_auth_method' => OauthClient::AUTH_NONE,
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'client_uri' => 'https://claude.ai',
            'is_dynamic' => true,
        ];
    }

    /** A client that proves itself with a secret rather than with PKCE alone. */
    public function confidential(string $secret): static
    {
        return $this->state(fn () => [
            'token_endpoint_auth_method' => OauthClient::AUTH_SECRET_POST,
            'secret_hash' => hash('sha256', $secret),
        ]);
    }
}
