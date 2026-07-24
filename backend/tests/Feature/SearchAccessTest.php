<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Search\DocumentSearch;
use App\Services\Search\SearchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Der Ordnerfilter wird serverseitig gesetzt. Er darf sich weder durch Parameter
 * aus dem Request noch durch eine leere Freigabeliste aushebeln lassen.
 */
class SearchAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_only_searches_within_the_folders_shared_with_them(): void
    {
        $allowed = WatchedFolder::factory()->create();
        $forbidden = WatchedFolder::factory()->create();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->folders()->attach($allowed);

        $index = $this->mockIndex(function (array $options) use ($allowed, $forbidden) {
            $this->assertContains('folder_id IN ['.$allowed->id.']', $options['filter']);
            $this->assertStringNotContainsString((string) $forbidden->id, implode(' ', array_filter($options['filter'], 'is_string')));
        });

        (new DocumentSearch($index))->search($user, 'rechnung');
    }

    #[Test]
    public function an_admin_searches_without_a_folder_filter(): void
    {
        WatchedFolder::factory()->count(2)->create();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $index = $this->mockIndex(function (array $options) {
            foreach ($options['filter'] as $clause) {
                $this->assertStringNotContainsString('folder_id IN', is_array($clause) ? implode(' ', $clause) : $clause);
            }
        });

        (new DocumentSearch($index))->search($admin, 'rechnung');
    }

    #[Test]
    public function a_user_without_any_share_gets_no_hits_rather_than_all_of_them(): void
    {
        WatchedFolder::factory()->count(3)->create();
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $index = Mockery::mock(SearchIndex::class);
        // Ohne Freigabe darf gar keine Abfrage rausgehen — ein leerer Filter
        // wäre hier gleichbedeutend mit „alles sehen".
        $index->shouldNotReceive('search');

        $result = (new DocumentSearch($index))->search($user, 'rechnung');

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['hits']);
    }

    #[Test]
    public function a_disabled_folder_drops_out_of_the_users_scope(): void
    {
        $folder = WatchedFolder::factory()->create(['enabled' => false]);
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $user->folders()->attach($folder);

        $this->assertFalse($user->canAccessFolder($folder->id));
    }

    private function mockIndex(callable $inspectOptions): SearchIndex&MockInterface
    {
        $index = Mockery::mock(SearchIndex::class);

        $index->shouldReceive('search')
            ->once()
            ->andReturnUsing(function (string $query, array $options) use ($inspectOptions) {
                $inspectOptions($options);

                return ['hits' => [], 'totalHits' => 0, 'page' => 1, 'hitsPerPage' => 20, 'totalPages' => 0];
            });

        return $index;
    }
}
