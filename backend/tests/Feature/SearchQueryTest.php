<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WatchedFolder;
use App\Services\Search\DocumentSearch;
use App\Services\Search\SearchIndex;
use App\Support\DocumentDto;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchQueryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function values_of_one_facet_are_ored_and_different_facets_are_anded(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $options = $this->capture($admin, [
            'extension' => ['pdf', 'docx'],
            'year' => ['2019'],
        ]);

        // Nested arrays are an OR in Meilisearch, the outer level an AND.
        $this->assertContains(['extension = "pdf"', 'extension = "docx"'], $options['filter']);
        $this->assertContains(['year = "2019"'], $options['filter']);
    }

    #[Test]
    public function an_unknown_facet_is_ignored_rather_than_passed_through(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $options = $this->capture($admin, ['irgendwas' => ['boes"; DROP']]);

        $this->assertSame([], $options['filter']);
    }

    #[Test]
    public function quotes_in_a_facet_value_are_escaped(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $options = $this->capture($admin, ['folder_label' => ['Akten "2019"']]);

        $this->assertContains(['folder_label = "Akten \\"2019\\""'], $options['filter']);
    }

    #[Test]
    public function the_full_text_is_never_sent_back_to_the_browser(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $options = $this->capture($admin, []);

        $this->assertNotContains('content', $options['attributesToRetrieve']);
        $this->assertContains('content', $options['attributesToCrop']);
    }

    #[Test]
    public function derived_facet_fields_are_built_from_the_document(): void
    {
        $folder = WatchedFolder::factory()->create(['label' => 'Rechnungen']);

        $dto = new DocumentDto(
            uuid: '11111111-2222-3333-4444-555555555555',
            instanceId: 1,
            instanceName: 'Hauptcloud',
            folderId: $folder->id,
            folderLabel: 'Rechnungen',
            path: 'Akten/2019/rechnung.pdf',
            name: 'rechnung.pdf',
            extension: 'pdf',
            mimeType: 'application/pdf',
            size: 5 * 1024 * 1024,
            modifiedAt: CarbonImmutable::parse('2019-03-14 10:00:00'),
            text: 'Inhalt',
        );

        $indexed = $dto->toSearchDocument();

        $this->assertSame(2019, $indexed['year']);
        $this->assertSame('2019-03', $indexed['month']);
        $this->assertSame('1to10mb', $indexed['size_bucket']);
        $this->assertSame('Akten/2019', $indexed['directory']);
        $this->assertSame(['Akten', '2019'], $indexed['path_segments']);
        // Meilisearch allows no hyphens in the document ID.
        $this->assertSame('11111111222233334444555555555555', $indexed['id']);
    }

    #[Test]
    public function a_document_without_an_extension_still_gets_a_facet_value(): void
    {
        $dto = new DocumentDto(
            uuid: 'a', instanceId: 1, instanceName: 'x', folderId: 1, folderLabel: 'y',
            path: 'LIESMICH', name: 'LIESMICH', extension: null, mimeType: null,
            size: 10, modifiedAt: null, text: '',
        );

        $this->assertSame('none', $dto->toSearchDocument()['extension']);
        $this->assertNull($dto->toSearchDocument()['year']);
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

        (new DocumentSearch($index))->search($user, 'suche', $filters);

        return $captured;
    }
}
