<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Search\SearchIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Prüft den HTTP-Weg der Suche — genau das, was ein reiner Dienst-Test nicht
 * abdeckt: die Serialisierung aus dem Frontend und Laravels Eingabe-Aufbereitung.
 */
class SearchEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function fakeIndex(?callable $inspect = null): void
    {
        $index = Mockery::mock(SearchIndex::class);
        $index->shouldReceive('search')->andReturnUsing(function ($query, $options) use ($inspect) {
            if ($inspect) {
                $inspect($query, $options);
            }

            return ['hits' => [], 'totalHits' => 0, 'page' => 1, 'hitsPerPage' => 20, 'totalPages' => 0];
        });

        $this->app->instance(SearchIndex::class, $index);
    }

    #[Test]
    public function an_empty_query_on_page_load_does_not_fail_validation(): void
    {
        // Das Frontend ruft die Suche beim Laden mit leerem Feld auf. Laravels
        // ConvertEmptyStringsToNull macht daraus null — das muss durchgehen.
        $this->fakeIndex();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->getJson('/api/search?q=&sort=relevance&page=1')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    #[Test]
    public function facet_filters_arrive_as_a_json_string_and_are_applied(): void
    {
        $captured = null;
        $this->fakeIndex(function ($query, $options) use (&$captured) {
            $captured = $options['filter'];
        });

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $filters = json_encode(['extension' => ['pdf', 'docx'], 'year' => ['2019']]);

        $this->actingAs($admin)
            ->getJson('/api/search?q=rechnung&filters='.urlencode($filters))
            ->assertOk();

        $this->assertContains(['extension = "pdf"', 'extension = "docx"'], $captured);
        $this->assertContains(['year = "2019"'], $captured);
    }

    #[Test]
    public function a_broken_filter_string_is_ignored_rather_than_fatal(): void
    {
        $this->fakeIndex();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        // Kaputtes JSON oder ein leeres Objekt dürfen keinen Fehler auslösen.
        $this->actingAs($admin)->getJson('/api/search?filters=kein-json')->assertOk();
        $this->actingAs($admin)->getJson('/api/search?filters='.urlencode('{}'))->assertOk();
    }
}
