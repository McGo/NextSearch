<?php

namespace App\Services\Search;

use App\Support\DocumentDto;
use Meilisearch\Client;
use Meilisearch\Contracts\DocumentsQuery;
use Meilisearch\Exceptions\ApiException;

/**
 * Everything NextSearch does with Meilisearch. The service sits on the internal
 * network with no published port and is never passed through to the browser —
 * every search runs through the backend, which sets the folder filter.
 */
class SearchIndex
{
    public function __construct(private readonly Client $client) {}

    public function name(): string
    {
        return (string) config('nextsearch.search.index');
    }

    /**
     * Creates the index and sets its settings. Idempotent, runs on every start
     * of the app container.
     */
    public function configure(): void
    {
        try {
            $this->client->getIndex($this->name());
        } catch (ApiException) {
            $this->client->createIndex($this->name(), ['primaryKey' => 'id']);
        }

        $this->client->index($this->name())->updateSettings([
            // path_segments sits high so a folder name in the query ranks the
            // documents in that folder well.
            'searchableAttributes' => ['name', 'title', 'path_segments', 'content', 'path', 'author'],

            // folder_id carries the access check, the rest are the facets.
            'filterableAttributes' => [
                'folder_id', 'instance_id', 'instance_name', 'folder_label',
                'path_segments',
                'extension', 'mime_type', 'year', 'month', 'size_bucket',
                'author', 'language', 'ocr_used', 'has_preview',
                // Tags, encoded per assignment: `t12` is global, `u17:t12`
                // belongs to user 17 alone. See TagService.
                'tag_tokens',
            ],

            'sortableAttributes' => ['modified_at', 'size', 'name'],
            'displayedAttributes' => ['*'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
            // Generous, because the tag facet holds one value per assignment
            // kind: the global tags plus every user's personal ones. The
            // backend throws away what the caller may not see — but only after
            // Meilisearch has counted it, so the ceiling has to sit above the
            // sum, not above what one person gets to look at.
            'faceting' => ['maxValuesPerFacet' => 1000],
            'pagination' => ['maxTotalHits' => 10_000],
            'stopWords' => ['der', 'die', 'das', 'und', 'oder', 'the', 'and', 'of'],
            'typoTolerance' => [
                'enabled' => true,
                'minWordSizeForTypos' => ['oneTypo' => 5, 'twoTypos' => 9],
            ],
        ]);
    }

    public function upsert(DocumentDto $dto): void
    {
        $this->client->index($this->name())->addDocuments([$dto->toSearchDocument()], 'id');
    }

    /**
     * @param  list<string>  $uuids
     */
    public function forget(array $uuids): void
    {
        if ($uuids === []) {
            return;
        }

        $ids = array_map(fn (string $uuid) => str_replace('-', '', $uuid), $uuids);
        $this->client->index($this->name())->deleteDocuments($ids);
    }

    /**
     * Partial update: the given fields are merged, everything else on the
     * document stays as it is. Each entry needs an `id`.
     *
     * Careful — an id Meilisearch doesn't know is created rather than skipped.
     * Only patch documents that are actually in the index.
     *
     * @param  list<array<string, mixed>>  $documents
     */
    public function patch(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $this->client->index($this->name())->updateDocuments($documents, 'id');
    }

    /**
     * Partial-updates just the folder label on the given documents. Used when a
     * folder is renamed: the facet and the hit subline follow the new name
     * without re-fetching or re-processing the files.
     *
     * @param  list<string>  $uuids
     */
    public function relabelDocuments(array $uuids, string $label): void
    {
        $this->patch(array_map(fn (string $uuid) => [
            'id' => str_replace('-', '', $uuid),
            'folder_label' => $label,
        ], $uuids));
    }

    /**
     * Removes all documents of a folder — on deletion or reset.
     */
    public function forgetFolder(int $folderId): void
    {
        $this->client->index($this->name())->deleteDocuments(['filter' => 'folder_id = '.$folderId]);
    }

    /**
     * Removes all documents of an instance — on deletion. Catches every hit by
     * instance_id, even one whose folder row is already gone.
     */
    public function forgetInstance(int $instanceId): void
    {
        $this->client->index($this->name())->deleteDocuments(['filter' => 'instance_id = '.$instanceId]);
    }

    /**
     * Empties the whole index. The counterpart to a full rebuild, and the way
     * to clear out documents orphaned by an interrupted deletion.
     */
    public function flush(): void
    {
        $this->client->index($this->name())->deleteAllDocuments();
    }

    /**
     * A page of document ids in the index — for reconciling against the
     * database. Returns fewer than $limit ids on the last page.
     *
     * @return list<string>
     */
    public function documentIdPage(int $offset, int $limit): array
    {
        $query = (new DocumentsQuery)->setOffset($offset)->setLimit($limit)->setFields(['id']);
        $results = $this->client->index($this->name())->getDocuments($query);

        return array_map(fn (array $doc) => (string) $doc['id'], $results->getResults());
    }

    /**
     * Removes documents by their raw index id (a uuid without dashes).
     *
     * @param  list<string>  $ids
     */
    public function deleteByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->client->index($this->name())->deleteDocuments(array_values($ids));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function search(string $query, array $options): array
    {
        return $this->client->index($this->name())->rawSearch($query, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        try {
            return $this->client->index($this->name())->stats();
        } catch (ApiException) {
            return ['numberOfDocuments' => 0, 'isIndexing' => false];
        }
    }
}
