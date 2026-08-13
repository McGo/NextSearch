<?php

namespace App\Services\Search;

use App\Models\Tag;
use App\Models\User;
use App\Services\Tags\TagService;

/**
 * Translates a search request from the interface into a Meilisearch query — and
 * enforces the user's folder filter while doing so.
 */
class DocumentSearch
{
    /** Facets derived from the file itself. */
    public const FACETS = [
        'instance_name', 'folder_label', 'path_segments', 'extension', 'year', 'size_bucket', 'ocr_used',
    ];

    /**
     * The two facets fed by tags. Both come out of the same index field
     * `tag_tokens`; what separates them is the scope of the tag behind a token.
     */
    public const FACET_TAGS = 'tags';

    public const FACET_MY_TAGS = 'my_tags';

    /** The index field carrying every assignment, own and other people's. */
    private const TAG_FIELD = 'tag_tokens';

    /**
     * Stands in when a tag filter resolves to nothing — an unknown or withdrawn
     * tag uuid. Matches no document, which is the honest answer: dropping the
     * clause would silently return everything.
     */
    private const NO_MATCH_TOKEN = 't0';

    /**
     * Placeholders for match highlighting. Meilisearch inserts them raw into the
     * text; only after escaping do they become <mark> tags. The characters are
     * chosen so they practically never occur in real documents — and even then
     * they'd be harmless after escaping.
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

    public function __construct(
        private readonly SearchIndex $index,
        private readonly TagService $tags,
    ) {}

    /**
     * @param  array<string, list<string>>  $filters  facet name => selected values
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

        // No shared folder means: no hits. Not "all hits".
        if ($allowed !== null && $allowed->isEmpty()) {
            return $this->emptyResult($page, $perPage);
        }

        // Token => tag, for everything this user may see. Both the filter and
        // the presentation run through it; a token that isn't in here belongs
        // to someone else and never leaves the backend.
        $visible = $this->tags->tokenIndex($user);

        $options = [
            'filter' => $this->buildFilter($allowed?->all(), $filters, $user, $visible),
            'facets' => [...self::FACETS, self::TAG_FIELD],
            'page' => max(1, $page),
            'hitsPerPage' => $perPage,
            'attributesToHighlight' => ['name', 'content'],
            'attributesToCrop' => ['content'],
            'cropLength' => 40,
            'cropMarker' => ' … ',
            // Deliberately no HTML tags: the snippet comes from foreign file
            // contents and only becomes markup after escaping.
            'highlightPreTag' => self::MARK_OPEN,
            'highlightPostTag' => self::MARK_CLOSE,
            // The full text itself doesn't travel over the wire, only the
            // highlighted snippet from _formatted.
            'attributesToRetrieve' => [
                'uuid', 'name', 'path', 'directory', 'extension', 'mime_type',
                'size', 'size_bucket', 'modified_at', 'year', 'author', 'title',
                'page_count', 'instance_name', 'folder_label', 'folder_id',
                'ocr_used', 'has_preview', 'tag_tokens',
            ],
        ];

        if (self::SORTS[$sort] ?? null) {
            $options['sort'] = self::SORTS[$sort];
        }

        $result = $this->index->search($query, $options);

        return [
            'hits' => array_map(fn (array $hit) => $this->presentHit($hit, $visible), $result['hits'] ?? []),
            'total' => $result['totalHits'] ?? 0,
            'page' => $result['page'] ?? $page,
            'per_page' => $result['hitsPerPage'] ?? $perPage,
            'total_pages' => $result['totalPages'] ?? 0,
            'facets' => $this->presentFacets($result['facetDistribution'] ?? [], $visible),
            'took_ms' => $result['processingTimeMs'] ?? null,
        ];
    }

    /**
     * @param  list<int>|null  $allowedFolderIds  null = administrator, no restriction
     * @param  array<string, list<string>>  $filters
     * @param  array<string, Tag>  $visible  token => tag
     * @return list<string>
     */
    private function buildFilter(?array $allowedFolderIds, array $filters, User $user, array $visible): array
    {
        $clauses = [];

        if ($allowedFolderIds !== null) {
            $clauses[] = 'folder_id IN ['.implode(', ', $allowedFolderIds).']';
        }

        foreach ($filters as $facet => $values) {
            if ($values === []) {
                continue;
            }

            if ($facet === self::FACET_TAGS || $facet === self::FACET_MY_TAGS) {
                $clauses[] = $this->tagClause($facet, $values, $user, $visible);

                continue;
            }

            if (! in_array($facet, self::FACETS, true)) {
                continue;
            }

            // Several values of the same facet are ORed, different facets are
            // ANDed — that's how facet filters are expected to behave.
            $clauses[] = array_map(
                fn (string $value) => sprintf('%s = %s', $facet, $this->quote($value)),
                array_values($values),
            );
        }

        return $clauses;
    }

    /**
     * Turns the tag uuids the client sent into index tokens. A uuid the user may
     * not use, or one that no longer exists, contributes nothing — and if that
     * leaves the clause empty, it matches nothing rather than everything.
     *
     * @param  list<string>  $uuids
     * @param  array<string, Tag>  $visible
     * @return list<string>
     */
    private function tagClause(string $facet, array $uuids, User $user, array $visible): array
    {
        $wanted = array_flip($uuids);
        $tokens = [];

        foreach ($visible as $token => $tag) {
            $matchesFacet = $facet === self::FACET_MY_TAGS ? $tag->isPersonal() : ! $tag->isPersonal();

            if ($matchesFacet && isset($wanted[$tag->uuid])) {
                $tokens[] = $token;
            }
        }

        if ($tokens === []) {
            $tokens = [self::NO_MATCH_TOKEN];
        }

        return array_map(
            fn (string $token) => sprintf('%s = %s', self::TAG_FIELD, $this->quote($token)),
            $tokens,
        );
    }

    private function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    /**
     * @param  array<string, mixed>  $hit
     * @param  array<string, Tag>  $visible
     * @return array<string, mixed>
     */
    private function presentHit(array $hit, array $visible): array
    {
        $formatted = $hit['_formatted'] ?? [];
        unset($hit['_formatted']);

        // The raw tokens hold other people's assignments too. They are resolved
        // here and thrown away — only what this user may see travels on.
        $tokens = $hit['tag_tokens'] ?? [];
        unset($hit['tag_tokens']);

        return $hit + [
            'highlighted_name' => $this->highlight($formatted['name'] ?? ($hit['name'] ?? '')),
            'snippet' => isset($formatted['content'])
                ? $this->highlight($formatted['content'])
                : null,
            'tags' => $this->resolveTags(is_array($tokens) ? $tokens : [], $visible),
        ];
    }

    /**
     * @param  list<mixed>  $tokens
     * @param  array<string, Tag>  $visible
     * @return list<array<string, mixed>>
     */
    private function resolveTags(array $tokens, array $visible): array
    {
        $tags = [];

        foreach ($tokens as $token) {
            $tag = is_string($token) ? ($visible[$token] ?? null) : null;

            if ($tag !== null) {
                $tags[sprintf('%05d:%s', $tag->position, $tag->slug)] = $tag->presentBrief();
            }
        }

        ksort($tags);

        return array_values($tags);
    }

    /**
     * Escape everything first, then turn the placeholders into <mark>. Whatever
     * comes from an indexed file must never become markup in the browser.
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
     * @param  array<string, Tag>  $visible
     * @return list<array{name: string, values: list<array<string, mixed>>}>
     */
    private function presentFacets(array $distribution, array $visible): array
    {
        // Tags sit at the top of the panel — they are the one filter a person
        // set deliberately, everything below is derived from the file.
        $facets = $this->presentTagFacets($distribution[self::TAG_FIELD] ?? [], $visible);

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
     * Splits the token distribution into the two tag facets. Tokens that aren't
     * in the visible map are other people's personal assignments — they are
     * counted by Meilisearch and dropped here.
     *
     * Values are ordered by the position an administrator gave the tag, not by
     * count: the vocabulary has an intended order, and a filter panel that
     * reshuffles itself with every query is hard to use.
     *
     * @param  array<string, int>  $distribution
     * @param  array<string, Tag>  $visible
     * @return list<array{name: string, values: list<array<string, mixed>>}>
     */
    private function presentTagFacets(array $distribution, array $visible): array
    {
        $buckets = [self::FACET_TAGS => [], self::FACET_MY_TAGS => []];

        foreach ($distribution as $token => $count) {
            $tag = $visible[$token] ?? null;

            if ($tag === null) {
                continue;
            }

            $facet = $tag->isPersonal() ? self::FACET_MY_TAGS : self::FACET_TAGS;

            $buckets[$facet][sprintf('%05d:%s', $tag->position, $tag->slug)] = [
                // The uuid is what a filter is expressed in; the token stays
                // inside the backend.
                'value' => $tag->uuid,
                'count' => $count,
                'slug' => $tag->slug,
                // Null for system tags — the interface translates those.
                'label' => $tag->name,
                'color' => $tag->color,
                'is_system' => $tag->is_system,
            ];
        }

        $facets = [];

        foreach ($buckets as $name => $values) {
            if ($values === []) {
                continue;
            }

            ksort($values);
            $facets[] = ['name' => $name, 'values' => array_values($values)];
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
