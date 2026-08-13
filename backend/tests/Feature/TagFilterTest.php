<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Search\DocumentSearch;
use App\Services\Search\SearchIndex;
use App\Services\Tags\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tags as a filter. The index holds one token per assignment — global ones for
 * everyone, personal ones carrying the user they belong to. Nothing that
 * belongs to somebody else may leave the backend, neither as a hit nor as a
 * facet count.
 */
class TagFilterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        WatchedFolder::factory()->create();

        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /**
     * @param  array<string, list<string>>  $filters
     * @return array<string, mixed>
     */
    private function capture(User $user, array $filters): array
    {
        $captured = [];

        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('search')->andReturnUsing(function ($query, $options) use (&$captured) {
            $captured = $options;

            return ['hits' => [], 'totalHits' => 0];
        });

        (new DocumentSearch($index, new TagService($index)))->search($user, 'suche', $filters);

        return $captured;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function searchWith(User $user, array $result): array
    {
        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('search')->andReturn($result);

        return (new DocumentSearch($index, new TagService($index)))->search($user, '');
    }

    private function flatten(array $filter): string
    {
        $parts = [];

        array_walk_recursive($filter, function ($clause) use (&$parts) {
            $parts[] = $clause;
        });

        return implode(' | ', $parts);
    }

    #[Test]
    public function a_global_tag_filters_on_its_plain_token(): void
    {
        $admin = $this->admin();
        $tag = Tag::factory()->create(['slug' => 'vertrag']);

        $options = $this->capture($admin, ['tags' => [$tag->uuid]]);

        $this->assertStringContainsString('tag_tokens = "t'.$tag->id.'"', $this->flatten($options['filter']));
    }

    #[Test]
    public function a_personal_tag_filters_on_the_token_of_the_caller(): void
    {
        $admin = $this->admin();
        $tag = Tag::factory()->personal()->create(['slug' => 'pruefen']);

        $options = $this->capture($admin, ['my_tags' => [$tag->uuid]]);

        $this->assertStringContainsString(
            'tag_tokens = "u'.$admin->id.':t'.$tag->id.'"',
            $this->flatten($options['filter']),
        );
    }

    #[Test]
    public function a_tag_uuid_nobody_knows_matches_nothing_rather_than_everything(): void
    {
        $admin = $this->admin();

        $options = $this->capture($admin, ['tags' => ['00000000-0000-0000-0000-000000000000']]);

        // A dropped clause would quietly widen the search to the whole index.
        $this->assertStringContainsString('tag_tokens = "t0"', $this->flatten($options['filter']));
    }

    #[Test]
    public function someone_elses_private_tag_cannot_be_filtered_on(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['role' => User::ROLE_USER]);
        $tag = Tag::factory()->ownedBy($other)->create(['slug' => 'fremd']);

        $options = $this->capture($admin, ['my_tags' => [$tag->uuid]]);

        $flat = $this->flatten($options['filter']);

        $this->assertStringNotContainsString('t'.$tag->id.'"', $flat);
        $this->assertStringContainsString('tag_tokens = "t0"', $flat);
    }

    #[Test]
    public function the_facets_split_into_shared_and_personal_and_drop_the_rest(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['role' => User::ROLE_USER]);

        $shared = Tag::factory()->create(['slug' => 'vertrag', 'name' => 'Vertrag', 'color' => 'success']);
        $mine = Tag::factory()->personal()->create(['slug' => 'pruefen', 'name' => 'Prüfen']);
        $theirs = Tag::factory()->ownedBy($other)->create(['slug' => 'fremd']);

        $result = $this->searchWith($admin, [
            'hits' => [],
            'totalHits' => 0,
            'facetDistribution' => [
                'tag_tokens' => [
                    $shared->token() => 12,
                    $mine->token($admin->id) => 3,
                    // Another user's own assignments — counted by Meilisearch,
                    // dropped here.
                    $theirs->token($other->id) => 99,
                    $mine->token($other->id) => 42,
                ],
            ],
        ]);

        $facets = collect($result['facets'])->keyBy('name');

        $this->assertSame(
            [['value' => $shared->uuid, 'count' => 12, 'slug' => 'vertrag', 'label' => 'Vertrag', 'color' => 'success', 'is_system' => false]],
            $facets['tags']['values'],
        );
        $this->assertCount(1, $facets['my_tags']['values']);
        $this->assertSame($mine->uuid, $facets['my_tags']['values'][0]['value']);
        $this->assertSame(3, $facets['my_tags']['values'][0]['count']);
    }

    #[Test]
    public function a_hit_carries_only_the_tags_the_caller_may_see(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create(['role' => User::ROLE_USER]);

        $shared = Tag::factory()->create(['slug' => 'vertrag', 'name' => 'Vertrag']);
        $theirs = Tag::factory()->ownedBy($other)->create(['slug' => 'fremd', 'name' => 'Fremd']);

        $result = $this->searchWith($admin, [
            'hits' => [[
                'uuid' => 'abc',
                'name' => 'rechnung.pdf',
                'tag_tokens' => [$shared->token(), $theirs->token($other->id)],
            ]],
            'totalHits' => 1,
        ]);

        $hit = $result['hits'][0];

        $this->assertSame(['vertrag'], collect($hit['tags'])->pluck('slug')->all());
        // The raw tokens stay in the backend.
        $this->assertArrayNotHasKey('tag_tokens', $hit);
    }

    #[Test]
    public function a_disabled_tag_disappears_from_the_facets(): void
    {
        $admin = $this->admin();
        $tag = Tag::factory()->create(['slug' => 'ruht', 'enabled' => false]);

        $result = $this->searchWith($admin, [
            'hits' => [],
            'totalHits' => 0,
            'facetDistribution' => ['tag_tokens' => [$tag->token() => 7]],
        ]);

        $this->assertSame([], collect($result['facets'])->pluck('name')->intersect(['tags', 'my_tags'])->all());
    }
}
