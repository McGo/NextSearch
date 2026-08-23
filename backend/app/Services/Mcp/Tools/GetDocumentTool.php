<?php

namespace App\Services\Mcp\Tools;

use App\Models\Document;
use App\Services\Mcp\McpIdentity;
use App\Services\Mcp\ToolResult;
use App\Services\OAuth\Scopes;
use App\Services\Tags\TagService;

/**
 * One document in full: what the index knows about it, what hangs on it, and
 * where to open it.
 *
 * Deliberately no file contents. What a document says arrives as a snippet with
 * the search hit; the file itself stays in NextSearch, where opening it is
 * visible and logged — see the closing section of docs/api.md.
 */
class GetDocumentTool implements Tool
{
    public function __construct(private readonly TagService $tags) {}

    public function name(): string
    {
        return 'get_document';
    }

    public function title(): string
    {
        return 'Get document';
    }

    public function description(): string
    {
        return <<<'TEXT'
        Return everything known about a single document: file name, path, size, type, dates,
        the instance and folder it comes from, its tags, and links to open it.

        Addressed by the `uuid` a search hit carries. Use it to check a candidate before
        tagging, or to hand the user a link. It does not return the file contents — the
        search delivers matching text as a snippet, the file itself is opened in NextSearch.
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
        return [
            'type' => 'object',
            'properties' => [
                'uuid' => [
                    'type' => 'string',
                    'description' => 'The uuid of the document, as it appears on a search hit.',
                ],
            ],
            'required' => ['uuid'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, McpIdentity $identity): ToolResult
    {
        $uuid = trim((string) ($arguments['uuid'] ?? ''));

        if ($uuid === '') {
            return ToolResult::error('Give the uuid of a document. Search first if you do not have one.');
        }

        /** @var Document|null $document */
        $document = Document::query()->with(['folder', 'instance'])->where('uuid', $uuid)->first();

        if ($document === null) {
            return ToolResult::error('No document with this uuid. It may have been removed from the index.');
        }

        // Folder shares are maintained in NextSearch alone — see
        // docs/permissions.md. A document outside them does not exist here.
        if (! $identity->user->canAccessFolder($document->watched_folder_id)) {
            return ToolResult::error('This document sits in a folder that is not shared with you.');
        }

        $tags = $this->tags->present($document, $identity->user);

        $data = [
            'uuid' => $document->uuid,
            'name' => $document->name,
            'path' => $document->remote_path,
            'directory' => trim(dirname($document->remote_path), '.'),
            'extension' => $document->extension,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'page_count' => $document->page_count,
            'modified_at' => $document->remote_modified_at?->toIso8601String(),
            'indexed_at' => $document->indexed_at?->toIso8601String(),
            'ocr_used' => (bool) $document->ocr_used,
            'instance' => $document->instance->name,
            'folder' => $document->folder->label,
            'tags' => array_map(fn (array $tag) => $tag['slug'], $tags),
            'url' => rtrim((string) config('app.url'), '/').'/documents/'.$document->uuid,
        ];

        return ToolResult::ok($this->render($data), $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function render(array $data): string
    {
        $lines = [$data['name'], ''];

        $rows = [
            'Path' => $data['path'],
            'Instance' => $data['instance'],
            'Folder' => $data['folder'],
            'Type' => $data['extension'],
            'Size' => $data['size'].' bytes',
            'Pages' => $data['page_count'],
            'Modified' => $data['modified_at'],
            'Indexed' => $data['indexed_at'],
            'Text from OCR' => $data['ocr_used'] ? 'yes' : 'no',
            'Tags' => $data['tags'] === [] ? 'none' : implode(', ', $data['tags']),
            'Open' => $data['url'],
        ];

        foreach ($rows as $label => $value) {
            if ($value !== null && $value !== '') {
                $lines[] = $label.': '.$value;
            }
        }

        return implode("\n", $lines);
    }
}
