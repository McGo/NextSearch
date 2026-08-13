<?php

namespace Tests\Feature;

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
 * Hanging tags on documents. Two things have to hold at once: the caller sees
 * the document, and the caller is allowed to use the tag.
 */
class TagAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private WatchedFolder $folder;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());

        $this->folder = WatchedFolder::factory()->create();
        $this->document = Document::factory()->for($this->folder, 'folder')->create([
            'nextcloud_instance_id' => $this->folder->nextcloud_instance_id,
        ]);
    }

    private function member(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->folders()->attach($this->folder);

        return $user;
    }

    #[Test]
    public function a_global_assignment_is_visible_to_everyone_with_access(): void
    {
        $one = $this->member();
        $two = $this->member();
        $tag = Tag::factory()->create(['slug' => 'vertrag', 'name' => 'Vertrag']);

        $this->actingAs($one)
            ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['vertrag']])
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'vertrag');

        $this->actingAs($two)
            ->getJson('/api/documents/'.$this->document->uuid.'/tags')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'vertrag');

        $this->assertDatabaseHas('document_tag', [
            'document_id' => $this->document->id,
            'tag_id' => $tag->id,
            'user_id' => null,
        ]);
    }

    #[Test]
    public function a_personal_assignment_stays_with_whoever_made_it(): void
    {
        $one = $this->member();
        $two = $this->member();
        Tag::factory()->personal()->create(['slug' => 'pruefen', 'name' => 'Prüfen']);

        $this->actingAs($one)
            ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['pruefen']])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($two)
            ->getJson('/api/documents/'.$this->document->uuid.'/tags')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    #[Test]
    public function each_person_keeps_their_own_row_for_a_personal_tag(): void
    {
        $one = $this->member();
        $two = $this->member();
        $tag = Tag::factory()->personal()->create(['slug' => 'pruefen']);

        foreach ([$one, $two] as $user) {
            $this->actingAs($user)
                ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['pruefen']])
                ->assertOk();
        }

        $this->assertSame(2, TagAssignment::query()
            ->where('document_id', $this->document->id)
            ->where('tag_id', $tag->id)
            ->count());

        // Removing it takes only one of the two away.
        $this->actingAs($one)
            ->deleteJson('/api/documents/'.$this->document->uuid.'/tags/pruefen')
            ->assertOk();

        $this->assertDatabaseHas('document_tag', ['tag_id' => $tag->id, 'user_id' => $two->id]);
        $this->assertDatabaseMissing('document_tag', ['tag_id' => $tag->id, 'user_id' => $one->id]);
    }

    #[Test]
    public function a_tag_reserved_for_admins_is_refused_to_everyone_else(): void
    {
        $user = $this->member();
        Tag::factory()->adminOnly()->create(['slug' => 'freigegeben']);

        $this->actingAs($user)
            ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['freigegeben']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tags');
    }

    #[Test]
    public function without_access_to_the_folder_there_is_no_tagging(): void
    {
        $outsider = User::factory()->create(['role' => User::ROLE_USER]);
        Tag::factory()->create(['slug' => 'vertrag']);

        $this->actingAs($outsider)
            ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['vertrag']])
            ->assertForbidden();
    }

    #[Test]
    public function someone_elses_private_tag_cannot_be_used(): void
    {
        $user = $this->member();
        $other = User::factory()->create(['role' => User::ROLE_USER]);
        Tag::factory()->ownedBy($other)->create(['slug' => 'fremd']);

        $this->actingAs($user)
            ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['fremd']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tags');
    }

    #[Test]
    public function ones_own_tag_wins_over_the_instance_tag_of_the_same_slug(): void
    {
        $user = $this->member();
        $instanceTag = Tag::factory()->create(['slug' => 'wichtig', 'name' => 'Wichtig (Instanz)']);
        $ownTag = Tag::factory()->ownedBy($user)->create(['slug' => 'wichtig', 'name' => 'Wichtig (meins)']);

        $this->actingAs($user)
            ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['wichtig']])
            ->assertOk();

        $this->assertDatabaseHas('document_tag', ['tag_id' => $ownTag->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('document_tag', ['tag_id' => $instanceTag->id]);
    }

    #[Test]
    public function put_replaces_the_set_the_caller_controls(): void
    {
        $user = $this->member();
        Tag::factory()->create(['slug' => 'alt']);
        Tag::factory()->create(['slug' => 'neu-dabei']);

        $this->actingAs($user)
            ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['alt']])
            ->assertOk();

        $response = $this->actingAs($user)
            ->putJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['neu-dabei']])
            ->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertSame(['neu-dabei'], $slugs);
    }

    #[Test]
    public function put_leaves_a_tag_the_caller_may_not_assign_alone(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = $this->member();
        Tag::factory()->adminOnly()->create(['slug' => 'freigegeben']);
        Tag::factory()->create(['slug' => 'alt']);

        $this->actingAs($admin)
            ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['freigegeben']])
            ->assertOk();

        $response = $this->actingAs($user)
            ->putJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['alt']])
            ->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->sort()->values()->all();

        $this->assertSame(['alt', 'freigegeben'], $slugs);
    }

    #[Test]
    public function an_unknown_tag_stops_the_call_instead_of_being_dropped(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['gibt-es-nicht']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tags');
    }

    #[Test]
    public function a_disabled_tag_is_no_longer_assignable(): void
    {
        $user = $this->member();
        Tag::factory()->create(['slug' => 'ruht', 'enabled' => false]);

        $this->actingAs($user)
            ->postJson('/api/documents/'.$this->document->uuid.'/tags', ['tags' => ['ruht']])
            ->assertStatus(422);
    }
}
