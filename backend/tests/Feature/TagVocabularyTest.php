<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use App\Services\Search\SearchIndex;
use App\Services\Tags\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who maintains which tag. Two kinds live side by side: the instance vocabulary
 * an administrator keeps, and the private tags anyone creates for themselves.
 */
class TagVocabularyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every write ends in the search index; here only the database matters.
        $this->instance(SearchIndex::class, Mockery::mock(SearchIndex::class)->shouldIgnoreMissing());
    }

    #[Test]
    public function a_user_creates_a_private_tag_and_it_is_personal(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->postJson('/api/tags', ['name' => 'Wichtig'])
            ->assertCreated()
            ->assertJsonPath('slug', 'wichtig')
            ->assertJsonPath('scope', Tag::SCOPE_PERSONAL)
            ->assertJsonPath('is_private', true);

        $this->assertDatabaseHas('tags', ['slug' => 'wichtig', 'owner_id' => $user->id]);
    }

    #[Test]
    public function a_new_tag_is_never_a_system_tag(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Only nextsearch:bootstrap creates those. The flag has to come back as
        // false, not as null from an unread column default.
        $this->actingAs($admin)
            ->postJson('/api/tags', ['name' => 'Vertrag', 'owner' => 'instance'])
            ->assertCreated()
            ->assertJsonPath('is_system', false);
    }

    #[Test]
    public function looking_a_tag_up_by_slug_does_not_compare_it_against_the_uuid_column(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $tag = Tag::factory()->create(['slug' => 'rechnung']);
        $service = app(TagService::class);

        // Postgres refuses `uuid = 'rechnung'` with a cast error rather than
        // returning no row. SQLite swallows it, so the query itself is checked.
        DB::enableQueryLog();
        $bySlug = $service->resolve('rechnung', $user);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($tag->is($bySlug));

        foreach ($queries as $query) {
            $this->assertStringNotContainsString('uuid', $query['query']);
        }

        // A key that does look like a uuid still resolves.
        $this->assertTrue($tag->is($service->resolve($tag->uuid, $user)));
        $this->assertNull($service->resolve('gibt-es-nicht', $user));
    }

    #[Test]
    public function a_user_may_not_add_to_the_instance_vocabulary(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->postJson('/api/tags', ['name' => 'Vertrag', 'owner' => 'instance'])
            ->assertForbidden();
    }

    #[Test]
    public function an_admin_creates_an_instance_tag_with_a_scope_and_a_policy(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/tags', [
                'name' => 'Freigegeben',
                'owner' => 'instance',
                'scope' => Tag::SCOPE_GLOBAL,
                'assign_policy' => Tag::POLICY_ADMIN,
                'color' => 'success',
            ])
            ->assertCreated()
            ->assertJsonPath('is_private', false)
            ->assertJsonPath('assign_policy', Tag::POLICY_ADMIN);
    }

    #[Test]
    public function the_same_slug_may_exist_once_per_owner(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        Tag::factory()->create(['slug' => 'wichtig', 'name' => 'Wichtig']);

        // The instance already has "wichtig" — the user's own is a different
        // tag and keeps the slug.
        $this->actingAs($user)
            ->postJson('/api/tags', ['name' => 'Wichtig'])
            ->assertCreated()
            ->assertJsonPath('slug', 'wichtig');

        // A second instance tag of the same name has to give way.
        $this->actingAs($admin)
            ->postJson('/api/tags', ['name' => 'Wichtig', 'owner' => 'instance'])
            ->assertCreated()
            ->assertJsonPath('slug', 'wichtig-2');
    }

    #[Test]
    public function the_list_shows_the_instance_vocabulary_and_ones_own_but_not_other_peoples(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $other = User::factory()->create(['role' => User::ROLE_USER]);

        Tag::factory()->create(['slug' => 'vertrag', 'name' => 'Vertrag']);
        Tag::factory()->ownedBy($user)->create(['slug' => 'meins', 'name' => 'Meins']);
        Tag::factory()->ownedBy($other)->create(['slug' => 'fremd', 'name' => 'Fremd']);

        $response = $this->actingAs($user)->getJson('/api/tags')->assertOk();

        $slugs = collect($response->json('data'))->pluck('slug')->all();

        $this->assertContains('vertrag', $slugs);
        $this->assertContains('meins', $slugs);
        $this->assertNotContains('fremd', $slugs);
    }

    #[Test]
    public function nobody_deletes_a_system_tag(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tag = Tag::factory()->system()->automatic()->create();

        $this->actingAs($admin)
            ->deleteJson('/api/tags/'.$tag->uuid)
            ->assertForbidden();

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    #[Test]
    public function an_admin_adjusts_name_colour_and_duration_of_a_system_tag(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tag = Tag::factory()->system()->automatic()->create();

        $this->actingAs($admin)
            ->patchJson('/api/tags/'.$tag->uuid, [
                'name' => 'Frisch',
                'color' => 'warning',
                'auto_duration_minutes' => 60,
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Frisch')
            ->assertJsonPath('auto_duration_minutes', 60)
            // Identity stays put.
            ->assertJsonPath('slug', Tag::SLUG_NEW)
            ->assertJsonPath('auto_rule', Tag::AUTO_NEW_DOCUMENT);
    }

    #[Test]
    public function the_scope_cannot_be_changed_after_the_fact(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $tag = Tag::factory()->create(['scope' => Tag::SCOPE_GLOBAL]);

        $this->actingAs($admin)
            ->patchJson('/api/tags/'.$tag->uuid, ['scope' => Tag::SCOPE_PERSONAL])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope');
    }

    #[Test]
    public function an_automatic_tag_has_to_be_global(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->postJson('/api/tags', [
                'name' => 'Neu-persönlich',
                'owner' => 'instance',
                'scope' => Tag::SCOPE_PERSONAL,
                'auto_rule' => Tag::AUTO_NEW_DOCUMENT,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('auto_rule');
    }

    #[Test]
    public function a_user_does_not_touch_someone_elses_private_tag(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $other = User::factory()->create(['role' => User::ROLE_USER]);
        $tag = Tag::factory()->ownedBy($other)->create();

        $this->actingAs($user)->patchJson('/api/tags/'.$tag->uuid, ['name' => 'X'])->assertNotFound();
        $this->actingAs($user)->deleteJson('/api/tags/'.$tag->uuid)->assertNotFound();
    }

    #[Test]
    public function a_user_does_not_touch_the_instance_vocabulary(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $tag = Tag::factory()->create();

        // Visible, but not theirs to edit — and an id that is not theirs says
        // nothing about whose it is.
        $this->actingAs($user)->patchJson('/api/tags/'.$tag->uuid, ['name' => 'X'])->assertNotFound();
    }
}
