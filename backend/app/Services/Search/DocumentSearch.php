<?php

namespace App\Services\Search;

use App\Models\User;

/**
 * Übersetzt eine Suchanfrage aus der Oberfläche in eine Meilisearch-Abfrage —
 * und erzwingt dabei den Ordnerfilter des Nutzers.
 */
class DocumentSearch
{
    /** Facetten, die die Oberfläche als Filterleiste anbietet. */
    public const FACETS = [
        'instance_name', 'folder_label', 'extension', 'year', 'size_bucket', 'ocr_used',
    ];

    /**
     * Platzhalter für die Trefferhervorhebung. Meilisearch setzt sie roh in den
     * Text; erst nach dem Escapen werden daraus <mark>-Tags. Die Zeichen sind
     * so gewählt, dass sie in echten Dokumenten praktisch nicht vorkommen — und
     * selbst dann wären sie nach dem Escapen harmlos.
     */
    private const MARK_OPEN = "\u{2E22}NS\u{2E23}";

    private const MARK_CLOSE = "\u{2E24}NS\u{2E25}";

    private const SORTS = [
        'relevance' => null,
        'newest' => ['modified_at:desc'],
        'oldest' => ['modified_at:asc'],
        'largest' => ['size:desc'],
        'name' => ['name:asc'],
    ];

    public function __construct(private readonly SearchIndex $index) {}

    /**
     * @param  array<string, list<string>>  $filters  Facettenname => gewählte Werte
     * @return array<string, mixed>
     */
    public function search(
        User $user,
        string $query,
        array $filters = [],
        string $sort = 'relevance',
        int $page = 1,
        int $perPage = 20,
    ): array {
        $allowed = $user->accessibleFolderIds();

        // Kein freigegebener Ordner heißt: keine Treffer. Nicht „alle Treffer".
        if ($allowed !== null && $allowed->isEmpty()) {
            return $this->emptyResult($page, $perPage);
        }

        $options = [
            'filter' => $this->buildFilter($allowed?->all(), $filters),
            'facets' => self::FACETS,
            'page' => max(1, $page),
            'hitsPerPage' => $perPage,
            'attributesToHighlight' => ['name', 'content'],
            'attributesToCrop' => ['content'],
            'cropLength' => 40,
            'cropMarker' => ' … ',
            // Bewusst keine HTML-Tags: der Ausschnitt stammt aus fremden
            // Dateiinhalten und wird erst nach dem Escapen zu Markup gemacht.
            'highlightPreTag' => self::MARK_OPEN,
            'highlightPostTag' => self::MARK_CLOSE,
            // Der Volltext selbst geht nicht mit über die Leitung, nur der
            // hervorgehobene Ausschnitt aus _formatted.
            'attributesToRetrieve' => [
                'uuid', 'name', 'path', 'directory', 'extension', 'mime_type',
                'size', 'size_bucket', 'modified_at', 'year', 'author', 'title',
                'page_count', 'instance_name', 'folder_label', 'folder_id',
                'ocr_used', 'has_preview',
            ],
        ];

        if (self::SORTS[$sort] ?? null) {
            $options['sort'] = self::SORTS[$sort];
        }

        $result = $this->index->search($query, $options);

        return [
            'hits' => array_map($this->presentHit(...), $result['hits'] ?? []),
            'total' => $result['totalHits'] ?? 0,
            'page' => $result['page'] ?? $page,
            'per_page' => $result['hitsPerPage'] ?? $perPage,
            'total_pages' => $result['totalPages'] ?? 0,
            'facets' => $this->presentFacets($result['facetDistribution'] ?? []),
            'took_ms' => $result['processingTimeMs'] ?? null,
        ];
    }

    /**
     * @param  list<int>|null  $allowedFolderIds  null = Administrator, keine Einschränkung
     * @param  array<string, list<string>>  $filters
     * @return list<string>
     */
    private function buildFilter(?array $allowedFolderIds, array $filters): array
    {
        $clauses = [];

        if ($allowedFolderIds !== null) {
            $clauses[] = 'folder_id IN ['.implode(', ', $allowedFolderIds).']';
        }

        foreach ($filters as $facet => $values) {
            if (! in_array($facet, self::FACETS, true) || $values === []) {
                continue;
            }

            // Mehrere Werte derselben Facette werden verodert, verschiedene
            // Facetten verundet — so verhalten sich Facettenfilter erwartbar.
            $clauses[] = array_map(
                fn (string $value) => sprintf('%s = %s', $facet, $this->quote($value)),
                array_values($values),
            );
        }

        return $clauses;
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>
     */
    private function presentHit(array $hit): array
    {
        $formatted = $hit['_formatted'] ?? [];
        unset($hit['_formatted']);

        return $hit + [
            'highlighted_name' => $this->highlight($formatted['name'] ?? ($hit['name'] ?? '')),
            'snippet' => isset($formatted['content'])
                ? $this->highlight($formatted['content'])
                : null,
        ];
    }

    /**
     * Erst alles escapen, dann die Platzhalter zu <mark> machen. Was aus einer
     * indizierten Datei kommt, darf im Browser kein Markup werden.
     */
    private function highlight(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(
            [self::MARK_OPEN, self::MARK_CLOSE],
            ['<mark>', '</mark>'],
            $escaped,
        );
    }

    /**
     * @param  array<string, array<string, int>>  $distribution
     * @return list<array{name: string, values: list<array{value: string, count: int}>}>
     */
    private function presentFacets(array $distribution): array
    {
        $facets = [];

        foreach (self::FACETS as $facet) {
            $values = $distribution[$facet] ?? [];

            if ($values === []) {
                continue;
            }

            arsort($values);

            $facets[] = [
                'name' => $facet,
                'values' => array_map(
                    fn ($value, $count) => ['value' => (string) $value, 'count' => $count],
                    array_keys($values),
                    array_values($values),
                ),
            ];
        }

        return $facets;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(int $page, int $perPage): array
    {
        return [
            'hits' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => 0,
            'facets' => [],
            'took_ms' => 0,
        ];
    }
}
