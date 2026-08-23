<?php

namespace App\Services\Mcp\Tools;

use App\Models\User;
use App\Services\Mcp\McpIdentity;
use App\Services\Mcp\ToolResult;
use App\Services\OAuth\Scopes;
use App\Services\Search\DocumentSearch;
use App\Services\Tags\TagService;

/**
 * Full-text search across everything the caller may see.
 *
 * The filters are the same facets the interface offers, only flattened: a model
 * fills `extension: ["pdf"]` reliably, a nested `filters` object rather less
 * so. Tags are accepted as slugs here and resolved to their uuids inside —
 * outside, nobody should have to know that the two tag facets differ.
 */
class SearchDocumentsTool implements Tool
{
    /** Characters of a snippet handed on. Beyond that it stops being a hint. */
    private const SNIPPET_LIMIT = 400;

    public function __construct(
        private readonly DocumentSearch $search,
        private readonly TagService $tags,
    ) {}

    public function name(): string
    {
        return 'search_documents';
    }

    public function title(): string
    {
        return 'Search documents';
    }

    public function description(): string
    {
        return <<<'TEXT'
        Search the full text of the documents indexed in NextSearch and return the matching
        ones with their metadata, a highlighted snippet and their tags.

        Covers the contents of PDF, Office, e-mail and text files from the connected
        Nextcloud folders, scanned documents included where OCR ran. Results are limited to
        the folders shared with the user this connection belongs to.

        Leave `query` empty to list documents by filter alone — for instance everything
        carrying a certain tag. The response also reports which facet values occur in the
        result set, which is the cheapest way to narrow a search that returned too much.

        Each hit carries a `uuid`; that is what tag_documents and get_document address.
        TEXT;
    }

    public function scope(): string
    {
        return Scopes::DOCUMENTS_READ;
    }

    public function readOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        $stringList = fn (string $description) => [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'description' => $description,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Words to look for in the text, the file name and the folder names. Leave out to filter only.',
                ],
                'tags' => $stringList('Tag slugs, e.g. ["vertrag"]. Several are ORed. Use list_tags for the vocabulary.'),
                'extension' => $stringList('File extensions without a dot, e.g. ["pdf", "docx"].'),
                'year' => $stringList('Years of the last modification, e.g. ["2019", "2020"].'),
                'instance' => $stringList('Names of the Nextcloud instances a document comes from.'),
                'folder' => $stringList('Labels of the watched folders.'),
                'directory' => $stringList('Names of folders anywhere in the path, e.g. ["Buchhaltung"].'),
                'size' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['upTo100kb', '100kbTo1mb', '1to10mb', '10to100mb', 'over100mb']],
                    'description' => 'Size buckets.',
                ],
                'ocr' => [
                    'type' => 'boolean',
                    'description' => 'true returns only documents whose text came out of OCR, false only those with a text layer of their own.',
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => ['relevance', 'newest', 'oldest', 'largest', 'name'],
                    'description' => 'Default relevance. Without a query, newest is usually the more useful order.',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Page of the result set, from 1. The response reports total_pages.',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => (int) config('nextsearch.mcp.max_results'),
                    'description' => 'Hits per page.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, McpIdentity $identity): ToolResult
    {
        $max = (int) config('nextsearch.mcp.max_results');
        $perPage = max(1, min($max, (int) ($arguments['per_page'] ?? min(10, $max))));

        try {
            $filters = $this->buildFilters($arguments, $identity->user);
        } catch (UnknownTagException $e) {
            return ToolResult::error($e->getMessage());
        }

        $result = $this->search->search(
            user: $identity->user,
            query: trim((string) ($arguments['query'] ?? '')),
            filters: $filters,
            sort: (string) ($arguments['sort'] ?? 'relevance'),
            page: max(1, (int) ($arguments['page'] ?? 1)),
            perPage: $perPage,
        );

        $hits = array_map(fn (array $hit) => $this->presentHit($hit), $result['hits']);

        return ToolResult::ok(
            $this->render($hits, $result),
            [
                'total' => $result['total'],
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total_pages' => $result['total_pages'],
                'hits' => $hits,
                'facets' => $this->presentFacets($result['facets']),
            ],
        );
    }

    /**
     * Translates the flat arguments into the facet filters DocumentSearch
     * expects. The folder restriction is not among them — that one the service
     * sets itself, from the user, never from anything a caller sent.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, list<string>>
     */
    private function buildFilters(array $arguments, User $user): array
    {
        $map = [
            'extension' => 'extension',
            'year' => 'year',
            'instance' => 'instance_name',
            'folder' => 'folder_label',
            'directory' => 'path_segments',
            'size' => 'size_bucket',
        ];

        $filters = [];

        foreach ($map as $argument => $facet) {
            $values = $arguments[$argument] ?? null;

            if (! is_array($values)) {
                continue;
            }

            $strings = array_values(array_filter(
                array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $values),
                fn (string $value) => $value !== '',
            ));

            if ($strings !== []) {
                $filters[$facet] = $strings;
            }
        }

        if (array_key_exists('ocr', $arguments) && is_bool($arguments['ocr'])) {
            $filters['ocr_used'] = [$arguments['ocr'] ? 'true' : 'false'];
        }

        return $filters + $this->tagFilters($arguments['tags'] ?? null, $user);
    }

    /**
     * Slugs to uuids, split across the two tag facets. An unknown slug stops
     * the call: a filter that quietly drops would return more than was asked
     * for, and a model has no way of telling.
     *
     * @return array<string, list<string>>
     */
    private function tagFilters(mixed $keys, User $user): array
    {
        if (! is_array($keys) || $keys === []) {
            return [];
        }

        $filters = [];
        $unknown = [];

        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $tag = $this->tags->resolve($key, $user);

            if ($tag === null) {
                $unknown[] = $key;

                continue;
            }

            $facet = $tag->isPersonal() ? DocumentSearch::FACET_MY_TAGS : DocumentSearch::FACET_TAGS;
            $filters[$facet][] = $tag->uuid;
        }

        if ($unknown !== []) {
            throw new UnknownTagException(sprintf(
                'Unknown tag(s): %s. Call list_tags for the vocabulary available to you.',
                implode(', ', $unknown),
            ));
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>
     */
    private function presentHit(array $hit): array
    {
        return [
            'uuid' => $hit['uuid'],
            'name' => $hit['name'],
            'path' => $hit['path'] ?? null,
            'extension' => $hit['extension'] ?? null,
            'size' => $hit['size'] ?? null,
            'modified_at' => isset($hit['modified_at'])
                ? date('Y-m-d', (int) $hit['modified_at'])
                : null,
            'instance' => $hit['instance_name'] ?? null,
            'folder' => $hit['folder_label'] ?? null,
            'ocr_used' => $hit['ocr_used'] ?? null,
            'tags' => array_map(fn (array $tag) => $tag['slug'], $hit['tags'] ?? []),
            'snippet' => $this->plainSnippet($hit['snippet'] ?? null),
            'url' => rtrim((string) config('app.url'), '/').'/documents/'.$hit['uuid'],
        ];
    }

    /**
     * The snippet comes out of the search as escaped HTML with <mark> around
     * the hits. A model wants none of that — the markup goes, the entities come
     * back as characters.
     */
    private function plainSnippet(?string $snippet): ?string
    {
        if ($snippet === null || $snippet === '') {
            return null;
        }

        $plain = html_entity_decode(strip_tags($snippet), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');

        return mb_strimwidth($plain, 0, self::SNIPPET_LIMIT, '…');
    }

    /**
     * Facets in the compact form: facet name => value => count. The tag facets
     * report slugs rather than uuids, because that is what goes back into the
     * next call.
     *
     * @param  list<array{name: string, values: list<array<string, mixed>>}>  $facets
     * @return array<string, array<string, int>>
     */
    private function presentFacets(array $facets): array
    {
        $out = [];

        foreach ($facets as $facet) {
            $isTagFacet = in_array($facet['name'], [DocumentSearch::FACET_TAGS, DocumentSearch::FACET_MY_TAGS], true);
            $values = [];

            foreach ($facet['values'] as $value) {
                $key = $isTagFacet ? (string) $value['slug'] : (string) $value['value'];
                $values[$key] = (int) $value['count'];
            }

            if ($values !== []) {
                $out[$facet['name']] = $values;
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @param  array<string, mixed>  $result
     */
    private function render(array $hits, array $result): string
    {
        if ($hits === []) {
            return 'No documents matched. Try fewer filters or other words — the search covers '
                .'the file contents, the file name and the folder names.';
        }

        $lines = [sprintf(
            '%d document(s) found, showing page %d of %d.',
            $result['total'],
            $result['page'],
            max(1, $result['total_pages']),
        ), ''];

        foreach ($hits as $index => $hit) {
            $meta = array_filter([
                $hit['folder'],
                $hit['instance'],
                $hit['modified_at'],
                $hit['size'] === null ? null : $this->humanSize((int) $hit['size']),
                $hit['tags'] === [] ? null : 'tags: '.implode(', ', $hit['tags']),
            ]);

            $lines[] = sprintf('%d. %s', $index + 1, $hit['name']);
            $lines[] = '   '.implode(' · ', $meta);
            $lines[] = '   uuid: '.$hit['uuid'];

            if ($hit['path'] !== null) {
                $lines[] = '   path: '.$hit['path'];
            }

            if ($hit['snippet'] !== null) {
                $lines[] = '   … '.$hit['snippet'];
            }

            $lines[] = '';
        }

        if ($result['total_pages'] > $result['page']) {
            $lines[] = sprintf('More hits on page %d.', $result['page'] + 1);
        }

        return trim(implode("\n", $lines));
    }

    private function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => $bytes.' B',
            $bytes < 1024 * 1024 => round($bytes / 1024).' KB',
            $bytes < 1024 * 1024 * 1024 => round($bytes / 1024 / 1024, 1).' MB',
            default => round($bytes / 1024 / 1024 / 1024, 1).' GB',
        };
    }
}
