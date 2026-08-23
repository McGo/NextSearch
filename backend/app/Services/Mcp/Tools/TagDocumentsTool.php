<?php

namespace App\Services\Mcp\Tools;

use App\Services\Mcp\McpIdentity;
use App\Services\Mcp\ToolResult;
use App\Services\OAuth\Scopes;
use App\Services\Tags\BulkTagger;
use Illuminate\Validation\ValidationException;

/**
 * Setting and removing tags, one document or five hundred.
 *
 * Runs through the same BulkTagger the interface and /api/v1 use, so the rules
 * are identical everywhere: a document in a folder that was never shared is
 * skipped, a tag the caller may not assign fails the whole call rather than
 * being dropped quietly.
 */
class TagDocumentsTool implements Tool
{
    public function __construct(private readonly BulkTagger $bulk) {}

    public function name(): string
    {
        return 'tag_documents';
    }

    public function title(): string
    {
        return 'Tag documents';
    }

    public function description(): string
    {
        return <<<'TEXT'
        Add tags to documents and remove tags from them. Both can happen in one call, and up
        to 500 documents at a time.

        Tags are addressed by their slug and have to exist — call list_tags first; this tool
        does not create them. Adding is additive: tags already on a document stay.

        Documents are addressed by the `uuid` from a search hit. Ones sitting in folders that
        are not shared with this user are reported as skipped instead of failing the call.
        Whether an assignment is visible to everyone or only to this user follows from the
        tag's scope, not from anything given here.
        TEXT;
    }

    public function scope(): string
    {
        return Scopes::TAGS_WRITE;
    }

    public function readOnly(): bool
    {
        return false;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'documents' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'minItems' => 1,
                    'maxItems' => BulkTagger::LIMIT,
                    'description' => 'Uuids of the documents, as they appear on search hits.',
                ],
                'add' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Slugs of the tags to hang on. Must exist — see list_tags.',
                ],
                'remove' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Slugs of the tags to take off.',
                ],
            ],
            'required' => ['documents'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, McpIdentity $identity): ToolResult
    {
        $documents = $this->strings($arguments['documents'] ?? null);
        $add = $this->strings($arguments['add'] ?? null);
        $remove = $this->strings($arguments['remove'] ?? null);

        if ($documents === []) {
            return ToolResult::error('Give at least one document uuid.');
        }

        if (count($documents) > BulkTagger::LIMIT) {
            return ToolResult::error(sprintf(
                'At most %d documents per call. Split the list.',
                BulkTagger::LIMIT,
            ));
        }

        if ($add === [] && $remove === []) {
            return ToolResult::error('Give at least one tag to add or to remove.');
        }

        try {
            $result = $this->bulk->apply(
                $identity->user,
                $documents,
                $this->bulk->lookup($add, $identity->user),
                $this->bulk->lookup($remove, $identity->user),
            );
        } catch (ValidationException $e) {
            // Unknown tag, or one reserved for administrators. The message is
            // the same one the interface shows, so both read alike.
            return ToolResult::error(implode(' ', $e->validator->errors()->all()));
        }

        return ToolResult::ok($this->render($result, $add, $remove), $result);
    }

    /**
     * @param  array{updated: int, skipped: list<string>, unknown: list<string>}  $result
     * @param  list<string>  $add
     * @param  list<string>  $remove
     */
    private function render(array $result, array $add, array $remove): string
    {
        $what = array_filter([
            $add === [] ? null : 'added '.implode(', ', $add),
            $remove === [] ? null : 'removed '.implode(', ', $remove),
        ]);

        $lines = [sprintf('%d document(s) updated — %s.', $result['updated'], implode('; ', $what))];

        if ($result['skipped'] !== []) {
            $lines[] = sprintf(
                '%d skipped, they sit in folders not shared with you: %s',
                count($result['skipped']),
                implode(', ', $result['skipped']),
            );
        }

        if ($result['unknown'] !== []) {
            $lines[] = sprintf(
                '%d uuid(s) matched no document: %s',
                count($result['unknown']),
                implode(', ', $result['unknown']),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($entry) => is_string($entry) ? trim($entry) : '', $value),
            fn (string $entry) => $entry !== '',
        )));
    }
}
