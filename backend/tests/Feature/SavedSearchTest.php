<?php

namespace Tests\Feature;

use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SavedSearchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_saves_a_search_with_its_query_filters_and_sort(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->postJson('/api/saved-searches', [
                'name' => 'Rechnungen 2019',
                'query' => 'rechnung',
                'filters' => ['year' => ['2019'], 'extension' => ['pdf']],
                'sort' => 'newest',
            ])
            ->assertCreated()
            ->assertJsonPath('name', 'Rechnungen 2019')
            ->assertJsonPath('query', 'rechnung')
            ->assertJsonPath('sort', 'newest')
            ->assertJsonPath('filters.year.0', '2019');

        $this->assertDatabaseHas('saved_searches', [
            'user_id' => $user->id,
            'name' => 'Rechnungen 2019',
        ]);
    }

    #[Test]
    public function a_name_is_required(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->postJson('/api/saved-searches', ['query' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    #[Test]
    public function the_list_only_returns_the_users_own_searches(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $other = User::factory()->create(['role' => User::ROLE_USER]);

        SavedSearch::factory()->for($user)->create(['name' => 'Meine']);
        SavedSearch::factory()->for($other)->create(['name' => 'Fremde']);

        $response = $this->actingAs($user)->getJson('/api/saved-searches')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Meine');
    }

    #[Test]
    public function a_user_deletes_their_own_saved_search(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $search = SavedSearch::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson('/api/saved-searches/'.$search->uuid)
            ->assertNoContent();

        $this->assertDatabaseMissing('saved_searches', ['id' => $search->id]);
    }

    #[Test]
    public function a_user_cannot_delete_someone_elses_saved_search(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $other = User::factory()->create(['role' => User::ROLE_USER]);
        $search = SavedSearch::factory()->for($other)->create();

        $this->actingAs($user)
            ->deleteJson('/api/saved-searches/'.$search->uuid)
            ->assertNotFound();

        $this->assertDatabaseHas('saved_searches', ['id' => $search->id]);
    }

    #[Test]
    public function unknown_filter_shapes_are_dropped_before_storing(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $this->actingAs($user)
            ->postJson('/api/saved-searches', [
                'name' => 'Nur Sauberes',
                'filters' => [
                    'extension' => ['pdf', '', 123],
                    'kaputt' => 'kein array',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('filters.extension', ['pdf'])
            ->assertJsonMissingPath('filters.kaputt');
    }
}
