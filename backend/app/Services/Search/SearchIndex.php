<?php

namespace App\Services\Search;

use App\Support\DocumentDto;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;

/**
 * Alles, was NextSearch mit Meilisearch macht. Der Dienst hängt ohne
 * Port-Freigabe im internen Netz und wird nie an den Browser durchgereicht —
 * jede Suche läuft über das Backend, das den Ordnerfilter setzt.
 */
class SearchIndex
{
    public function __construct(private readonly Client $client) {}

    public function name(): string
    {
        return (string) config('nextsearch.search.index');
    }

    /**
     * Legt den Index an und setzt seine Einstellungen. Idempotent, läuft bei
     * jedem Start des App-Containers.
     */
    public function configure(): void
    {
        try {
            $this->client->getIndex($this->name());
        } catch (ApiException) {
            $this->client->createIndex($this->name(), ['primaryKey' => 'id']);
        }

        $this->client->index($this->name())->updateSettings([
            'searchableAttributes' => ['name', 'title', 'content', 'path', 'author'],

            // folder_id trägt die Zugriffsprüfung, der Rest sind die Facetten.
            'filterableAttributes' => [
                'folder_id', 'instance_id', 'instance_name', 'folder_label',
                'extension', 'mime_type', 'year', 'month', 'size_bucket',
                'author', 'language', 'ocr_used', 'has_preview',
            ],

            'sortableAttributes' => ['modified_at', 'size', 'name'],
            'displayedAttributes' => ['*'],
            'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness'],
            'faceting' => ['maxValuesPerFacet' => 200],
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
     * Entfernt alle Dokumente eines Ordners — beim Löschen oder Zurücksetzen.
     */
    public function forgetFolder(int $folderId): void
    {
        $this->client->index($this->name())->deleteDocuments(['filter' => 'folder_id = '.$folderId]);
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
