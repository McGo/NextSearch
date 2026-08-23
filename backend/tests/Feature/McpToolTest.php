<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Document;
use App\Models\OauthClient;
use App\Models\Tag;
use App\Models\TagAssignment;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\OAuth\OAuthServer;
use App\Services\OAuth\Scopes;
use App\Services\Search\SearchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The four tools. What is checked here is not that Meilisearch works — that has
 * its own tests — but that the tool layer hands the right thing down and the
 * right thing back: folder shares hold, tag slugs resolve, and a connection
 * that was only granted reading cannot write.
 */
class McpToolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WatchedFolder $folder;

    private Document $document;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://search.example.org']);

        $this->folder = WatchedFolder::factory()->create(['label' => 'Buchhaltung']);
        $this->document = Document::factory()->for($this->folder, 'folder')->create([
            'nextcloud_instance_id' => $this->folder->nextcloud_instance_id,
            'name' => 'rechnung-2019.pdf',
            'remote_path' => 'Buchhaltung/2019/rechnung-2019.pdf',
        ]);

        $this->user = User::factory()->create(['role' => User::ROLE_USER]);
        $this->user->folders()->attach($this->folder);

        $this->key = ApiToken::issue($this->user, 'Claude')['plain'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callTool(string $name, array $arguments = [], ?string $token = null): array
    {
        $response = $this->withToken($token ?? $this->key)->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ])->assertOk();

        return $response->json('result') ?? $response->json();
    }

    private function fakeIndex(?callable $inspect = null, array $hits = []): void
    {
        $index = Mockery::mock(SearchIndex::class)->shouldIgnoreMissing();
        $index->shouldReceive('search')->andReturnUsing(function ($query, $options) use ($inspect, $hits) {
            if ($inspect) {
                $inspect($query, $options);
            }

            return [
                'hits' => $hits,
                'totalHits' => count($hits),
                'page' => 1,
                'hitsPerPage' => 10,
                'totalPages' => $hits === [] ? 0 : 1,
                'processingTimeMs' => 3,
            ];
        });

        $this->app->instance(SearchIndex::class, $index);
    }

    #[Test]
    public function a_search_returns_hits_a_model_can_work_with(): void
    {
        $this->fakeIndex(hits: [[
            'uuid' => $this->document->uuid,
            'name' => 'rechnung-2019.pdf',
            'path' => 'Buchhaltung/2019/rechnung-2019.pdf',
            'extension' => 'pdf',
            'size' => 184320,
            'modified_at' => 1552521600,
            'instance_name' => 'Büro',
            'folder_label' => 'Buchhaltung',
            '_formatted' => ['content' => 'über 400 ⸢NS⸣Dachziegel⸤NS⸥ Hohlpfanne'],
        ]]);

        $result = $this->callTool('search_documents', ['query' => 'dachziegel']);

        $this->assertArrayNotHasKey('isError', $result);
        $this->assertSame(1, $result['structuredContent']['total']);
        $this->assertSame($this->document->uuid, $result['structuredContent']['hits'][0]['uuid']);

        // The snippet arrives as plain text — no <mark>, no entities.
        $snippet = $result['structuredContent']['hits'][0]['snippet'];
        $this->assertStringNotContainsString('<mark>', $snippet);
        $this->assertStringContainsString('Dachziegel', $snippet);

        // And the readable half carries the uuid, because that is what the
        // next call needs.
        $this->assertStringContainsString($this->document->uuid, $result['content'][0]['text']);
    }

    #[Test]
    public function the_flat_filters_become_facet_filters(): void
    {
        $captured = null;
        $this->fakeIndex(function ($query, $options) use (&$captured) {
            $captured = $options['filter'];
        });

        $this->callTool('search_documents', [
            'query' => 'rechnung',
            'extension' => ['pdf', 'docx'],
            'year' => ['2019'],
            'directory' => ['Buchhaltung'],
            'ocr' => true,
        ]);

        $this->assertContains(['extension = "pdf"', 'extension = "docx"'], $captured);
        $this->assertContains(['year = "2019"'], $captured);
        $this->assertContains(['path_segments = "Buchhaltung"'], $captured);
        $this->assertContains(['ocr_used = "true"'], $captured);

        // The folder restriction is not up to the caller — it comes from the
        // shares and is always there.
        $this->assertContains('folder_id IN ['.$this->folder->id.']', $captured);
    }

    #[Test]
    public function a_tag_slug_becomes_a_tag_filter(): void
    {
        $tag = Tag::factory()->create(['slug' => 'vertrag']);

        $captured = null;
        $this->fakeIndex(function ($query, $options) use (&$captured) {
            $captured = $options['filter'];
        });

        $this->callTool('search_documents', ['tags' => ['vertrag']]);

        $this->assertContains(['tag_tokens = "t'.$tag->id.'"'], $captured);
    }

    #[Test]
    public function an_unknown_tag_stops_the_search_instead_of_widening_it(): void
    {
        $this->fakeIndex();

        $result = $this->callTool('search_documents', ['tags' => ['gibtsnicht']]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('gibtsnicht', $result['content'][0]['text']);
    }

    #[Test]
    public function a_document_is_returned_with_its_tags(): void
    {
        $tag = Tag::factory()->create(['slug' => 'vertrag']);
        TagAssignment::query()->create([
            'document_id' => $this->document->id,
            'tag_id' => $tag->id,
            'assigned_by_id' => $this->user->id,
            'source' => TagAssignment::SOURCE_MANUAL,
        ]);

        $result = $this->callTool('get_document', ['uuid' => $this->document->uuid]);

        $this->assertSame('rechnung-2019.pdf', $result['structuredContent']['name']);
        $this->assertSame(['vertrag'], $result['structuredContent']['tags']);
        $this->assertSame(
            'https://search.example.org/documents/'.$this->document->uuid,
            $result['structuredContent']['url'],
        );
    }

    #[Test]
    public function a_document_from_a_folder_that_was_never_shared_stays_invisible(): void
    {
        $other = Document::factory()->create();

        $result = $this->callTool('get_document', ['uuid' => $other->uuid]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('not shared', $result['content'][0]['text']);
    }

    #[Test]
    public function the_vocabulary_lists_what_this_user_may_use(): void
    {
        Tag::factory()->create(['slug' => 'vertrag', 'name' => 'Vertrag']);
        Tag::factory()->adminOnly()->create(['slug' => 'freigegeben', 'name' => 'Freigegeben']);
        Tag::factory()->ownedBy(User::factory()->create())->create(['slug' => 'fremd']);

        $result = $this->callTool('list_tags');
        $slugs = array_column($result['structuredContent']['tags'], 'slug');

        $this->assertContains('vertrag', $slugs);
        $this->assertContains('freigegeben', $slugs);
        // Somebody else's private tag is none of this user's business.
        $this->assertNotContains('fremd', $slugs);

        $admin = collect($result['structuredContent']['tags'])->firstWhere('slug', 'freigegeben');
        $this->assertFalse($admin['assignable']);
    }

    #[Test]
    public function tagging_adds_and_removes_in_one_call(): void
    {
        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());

        Tag::factory()->create(['slug' => 'vertrag']);
        $old = Tag::factory()->create(['slug' => 'unsortiert']);

        TagAssignment::query()->create([
            'document_id' => $this->document->id,
            'tag_id' => $old->id,
            'assigned_by_id' => $this->user->id,
            'source' => TagAssignment::SOURCE_MANUAL,
        ]);

        $result = $this->callTool('tag_documents', [
            'documents' => [$this->document->uuid],
            'add' => ['vertrag'],
            'remove' => ['unsortiert'],
        ]);

        $this->assertSame(1, $result['structuredContent']['updated']);
        $this->assertSame(['vertrag'], $this->document->fresh()->tagAssignments()
            ->with('tag')->get()->pluck('tag.slug')->all());
    }

    #[Test]
    public function documents_out_of_reach_are_skipped_and_unknown_ones_reported(): void
    {
        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());

        Tag::factory()->create(['slug' => 'vertrag']);
        $foreign = Document::factory()->create();
        $nonsense = '00000000-0000-4000-8000-000000000000';

        $result = $this->callTool('tag_documents', [
            'documents' => [$this->document->uuid, $foreign->uuid, $nonsense],
            'add' => ['vertrag'],
        ]);

        $this->assertSame(1, $result['structuredContent']['updated']);
        $this->assertSame([$foreign->uuid], $result['structuredContent']['skipped']);
        $this->assertSame([$nonsense], $result['structuredContent']['unknown']);
    }

    #[Test]
    public function a_tag_reserved_for_administrators_fails_the_whole_call(): void
    {
        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());

        Tag::factory()->adminOnly()->create(['slug' => 'freigegeben']);

        $result = $this->callTool('tag_documents', [
            'documents' => [$this->document->uuid],
            'add' => ['freigegeben'],
        ]);

        $this->assertTrue($result['isError']);
        $this->assertSame(0, $this->document->tagAssignments()->count());
    }

    #[Test]
    public function a_connection_granted_reading_only_cannot_tag(): void
    {
        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());
        Tag::factory()->create(['slug' => 'vertrag']);

        $client = OauthClient::factory()->create();
        $token = $this->readOnlyToken($client);

        $result = $this->callTool('tag_documents', [
            'documents' => [$this->document->uuid],
            'add' => ['vertrag'],
        ], $token);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString(Scopes::TAGS_WRITE, $result['content'][0]['text']);
        $this->assertSame(0, $this->document->tagAssignments()->count());

        // Reading still works — that is what it was granted.
        $this->fakeIndex();
        $search = $this->callTool('search_documents', [], $token);
        $this->assertArrayNotHasKey('isError', $search);
    }

    /**
     * Walks the flow far enough to hold a token that carries reading alone.
     */
    private function readOnlyToken(OauthClient $client): string
    {
        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = app(OAuthServer::class)->issueAuthorizationCode(
            client: $client,
            user: $this->user,
            redirectUri: $client->redirect_uris[0],
            scopes: [Scopes::DOCUMENTS_READ],
            challenge: $challenge,
        );

        return app(OAuthServer::class)->exchangeAuthorizationCode(
            $client,
            $code,
            $client->redirect_uris[0],
            $verifier,
        )['access_token'];
    }
}
