<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The protocol layer of the MCP endpoint: what a client sees before it calls a
 * single tool.
 *
 * The 401 matters as much as the happy path. It is the only thing that tells a
 * client such as Claude Desktop that there is an authorization server here —
 * without the header pointing at the resource metadata, the connection stops
 * instead of starting the browser flow.
 */
class McpProtocolTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://search.example.org']);

        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->key = ApiToken::issue($user, 'Claude')['plain'];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function rpc(array $message, ?string $token = null): TestResponse
    {
        return $this->withToken($token ?? $this->key)->postJson('/api/mcp', $message);
    }

    #[Test]
    public function without_a_token_the_endpoint_points_at_its_metadata(): void
    {
        $response = $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $response->assertUnauthorized();

        $this->assertStringContainsString(
            'resource_metadata="https://search.example.org/.well-known/oauth-protected-resource/api/mcp"',
            $response->headers->get('WWW-Authenticate') ?? '',
        );
    }

    #[Test]
    public function an_unknown_token_is_refused_the_same_way(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], 'nxs_nonsense');

        $response->assertUnauthorized();
        $this->assertStringContainsString('error="invalid_token"', $response->headers->get('WWW-Authenticate') ?? '');
    }

    #[Test]
    public function get_is_refused_because_the_server_opens_no_stream(): void
    {
        $this->withToken($this->key)->getJson('/api/mcp')->assertStatus(405);
    }

    #[Test]
    public function initialize_reports_the_tools_capability(): void
    {
        $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass],
        ])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'NextSearch')
            ->assertJsonPath('result.capabilities.tools.listChanged', false)
            ->assertJsonStructure(['result' => ['instructions']]);
    }

    #[Test]
    public function a_protocol_version_we_do_not_speak_gets_ours_back(): void
    {
        // The client then decides whether it can live with it — that is the
        // negotiation the specification asks for.
        $this->rpc([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '1999-01-01'],
        ])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', config('nextsearch.mcp.protocol_versions.0'));
    }

    #[Test]
    public function the_four_tools_are_listed_with_their_schemas(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->assertOk();

        $names = array_column($response->json('result.tools'), 'name');

        $this->assertEqualsCanonicalizing(
            ['search_documents', 'get_document', 'list_tags', 'tag_documents'],
            $names,
        );

        foreach ($response->json('result.tools') as $tool) {
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertNotEmpty($tool['description']);
        }

        // Only the tagging one writes; a client may confirm that one and let
        // the rest through.
        $writing = array_values(array_filter(
            $response->json('result.tools'),
            fn (array $tool) => $tool['annotations']['readOnlyHint'] === false,
        ));

        $this->assertCount(1, $writing);
        $this->assertSame('tag_documents', $writing[0]['name']);
    }

    #[Test]
    public function a_notification_is_acknowledged_without_a_body(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertStatus(202)
            ->assertNoContent(202);
    }

    #[Test]
    public function an_unknown_method_comes_back_as_a_json_rpc_error(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/list'])
            ->assertOk()
            ->assertJsonPath('error.code', -32601);
    }

    #[Test]
    public function batches_and_broken_json_are_refused_as_parse_errors(): void
    {
        $this->withToken($this->key)
            ->postJson('/api/mcp', [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']])
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32700);

        $this->call('POST', '/api/mcp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->key,
        ], '{nope')->assertStatus(400);
    }

    #[Test]
    public function ping_answers_with_an_empty_result(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping'])
            ->assertOk()
            ->assertJsonPath('id', 4)
            ->assertJsonPath('result', []);
    }

    #[Test]
    public function a_switched_off_installation_has_no_endpoint_at_all(): void
    {
        config(['nextsearch.mcp.enabled' => false]);

        $this->rpc(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping'])->assertNotFound();
        $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
    }
}
