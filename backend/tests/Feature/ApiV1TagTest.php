<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Document;
use App\Models\Tag;
use App\Models\TagAssignment;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Search\SearchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tagging over /api/v1 with a personal key. The endpoints run the same
 * controllers as the interface — what is checked here is that the key carries
 * its owner's permissions and nothing more.
 */
class ApiV1TagTest extends TestCase
{
    use RefreshDatabase;

    private WatchedFolder $folder;

    private Document $document;

    private User $user;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());

        $this->folder = WatchedFolder::factory()->create();
        $this->document = Document::factory()->for($this->folder, 'folder')->create([
            'nextcloud_instance_id' => $this->folder->nextcloud_instance_id,
        ]);

        $this->user = User::factory()->create(['role' => User::ROLE_USER]);
        $this->user->folders()->attach($this->folder);

        $this->key = ApiToken::issue($this->user, 'Testschlüssel')['plain'];
    }

    private function api(): self
    {
        return $this->withToken($this->key);
    }

    #[Test]
    public function the_vocabulary_can_be_read(): void
    {
        Tag::factory()->create(['slug' => 'vertrag', 'name' => 'Vertrag']);

        $this->api()
            ->getJson('/api/v1/tags')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'vertrag');
    }

    #[Test]
    public function a_tag_is_added_by_slug_and_removed_again(): void
    {
        Tag::factory()->create(['slug' => 'vertrag', 'name' => 'Vertrag']);

        $this->api()
            ->postJson('/api/v1/documents/'.$this->document->uuid.'/tags', ['tags' => ['vertrag']])
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'vertrag');

        $this->api()
            ->deleteJson('/api/v1/documents/'.$this->document->uuid.'/tags/vertrag')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_tag_can_just_as_well_be_addressed_by_uuid(): void
    {
        $tag = Tag::factory()->create(['slug' => 'vertrag']);

        $this->api()
            ->postJson('/api/v1/documents/'.$this->document->uuid.'/tags', ['tags' => [$tag->uuid]])
            ->assertOk()
            ->assertJsonPath('data.0.uuid', $tag->uuid);
    }

    #[Test]
    public function the_bulk_call_tags_many_documents_at_once(): void
    {
        Tag::factory()->create(['slug' => 'vertrag']);
        Tag::factory()->create(['slug' => 'alt']);

        $second = Document::factory()->for($this->folder, 'folder')->create([
            'nextcloud_instance_id' => $this->folder->nextcloud_instance_id,
        ]);

        $this->api()
            ->postJson('/api/v1/documents/tags', [
                'documents' => [$this->document->uuid, $second->uuid],
                'add' => ['vertrag'],
            ])
            ->assertOk()
            ->assertJsonPath('updated', 2);

        $this->assertDatabaseCount('document_tag', 2);

        // Adding and removing in one call.
        $this->api()
            ->postJson('/api/v1/documents/tags', [
                'documents' => [$this->document->uuid],
                'add' => ['alt'],
                'remove' => ['vertrag'],
            ])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('document_tag', ['document_id' => $this->document->id]);
        $this->assertSame(1, TagAssignment::query()
            ->where('document_id', $this->document->id)->count());
    }

    #[Test]
    public function the_bulk_call_skips_what_the_caller_may_not_see(): void
    {
        Tag::factory()->create(['slug' => 'vertrag']);

        $otherFolder = WatchedFolder::factory()->create();
        $outOfReach = Document::factory()->for($otherFolder, 'folder')->create([
            'nextcloud_instance_id' => $otherFolder->nextcloud_instance_id,
        ]);

        $this->api()
            ->postJson('/api/v1/documents/tags', [
                'documents' => [$this->document->uuid, $outOfReach->uuid, '00000000-0000-0000-0000-000000000000'],
                'add' => ['vertrag'],
            ])
            ->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('skipped', [$outOfReach->uuid])
            ->assertJsonPath('unknown', ['00000000-0000-0000-0000-000000000000']);
    }

    #[Test]
    public function a_key_does_not_reach_beyond_its_owners_folders(): void
    {
        Tag::factory()->create(['slug' => 'vertrag']);

        $otherFolder = WatchedFolder::factory()->create();
        $outOfReach = Document::factory()->for($otherFolder, 'folder')->create([
            'nextcloud_instance_id' => $otherFolder->nextcloud_instance_id,
        ]);

        $this->api()
            ->postJson('/api/v1/documents/'.$outOfReach->uuid.'/tags', ['tags' => ['vertrag']])
            ->assertForbidden();
    }

    #[Test]
    public function a_normal_users_key_does_not_open_the_instance_vocabulary(): void
    {
        $this->api()
            ->postJson('/api/v1/tags', ['name' => 'Vertrag', 'owner' => 'instance'])
            ->assertForbidden();

        // Their own tag, on the other hand, is fine.
        $this->api()
            ->postJson('/api/v1/tags', ['name' => 'Wichtig'])
            ->assertCreated()
            ->assertJsonPath('is_private', true);
    }

    #[Test]
    public function searching_returns_the_documents_own_tags(): void
    {
        $tag = Tag::factory()->create(['slug' => 'vertrag', 'name' => 'Vertrag']);

        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('search')->andReturn([
            'hits' => [['uuid' => $this->document->uuid, 'name' => 'rechnung.pdf', 'tag_tokens' => [$tag->token()]]],
            'totalHits' => 1,
        ]);
        $index->shouldIgnoreMissing();
        $this->instance(SearchIndex::class, $index);

        $this->api()
            ->getJson('/api/v1/documents?q=rechnung')
            ->assertOk()
            ->assertJsonPath('hits.0.tags.0.slug', 'vertrag');
    }
}
