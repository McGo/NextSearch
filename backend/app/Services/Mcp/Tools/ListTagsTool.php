<?php

namespace App\Services\Mcp\Tools;

use App\Models\Tag;
use App\Services\Mcp\McpIdentity;
use App\Services\Mcp\ToolResult;
use App\Services\OAuth\Scopes;
use App\Services\Tags\TagService;

/**
 * The vocabulary this connection may work with: the instance tags plus the
 * user's own private ones. Nobody else's ever show up here.
 *
 * Without this tool the tagging one is guesswork — a model cannot know that the
 * installation calls it "vertrag" and not "contracts".
 */
class ListTagsTool implements Tool
{
    public function __construct(private readonly TagService $tags) {}

    public function name(): string
    {
        return 'list_tags';
    }

    public function title(): string
    {
        return 'List tags';
    }

    public function description(): string
    {
        return <<<'TEXT'
        List the tags available to this user: the shared vocabulary of the installation plus
        their own private ones.

        Call this before filtering a search by tag or before tagging anything — tags are
        addressed by their slug, and only the ones listed here exist. `assignable` says
        whether this user may hang the tag on a document; some are reserved for
        administrators. `scope` says who sees an assignment: `global` everyone with access
        to the document, `personal` only the person who made it.
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
            'properties' => new \stdClass,
            'additionalProperties' => false,
        ];
    }

    public function handle(array $arguments, McpIdentity $identity): ToolResult
    {
        $user = $identity->user;

        $tags = $this->tags->visibleTo($user)->map(fn (Tag $tag) => [
            'slug' => $tag->slug,
            // System tags carry no name of their own; the interface translates
            // them from the slug, and here the slug has to do the same job.
            'name' => $tag->name ?? $tag->slug,
            'description' => $tag->description,
            'scope' => $tag->scope,
            'private' => $tag->isPrivate(),
            'automatic' => $tag->auto_rule !== null,
            'assignable' => $tag->assignableBy($user),
        ])->values()->all();

        if ($tags === []) {
            return ToolResult::ok('No tags defined yet.', ['tags' => []]);
        }

        $lines = array_map(function (array $tag) {
            $notes = array_filter([
                $tag['scope'] === Tag::SCOPE_PERSONAL ? 'personal' : null,
                $tag['private'] ? 'own' : null,
                $tag['automatic'] ? 'assigned automatically' : null,
                $tag['assignable'] ? null : 'read only',
            ]);

            return sprintf(
                '- %s (%s)%s%s',
                $tag['slug'],
                $tag['name'],
                $notes === [] ? '' : ' — '.implode(', ', $notes),
                $tag['description'] === null ? '' : ': '.$tag['description'],
            );
        }, $tags);

        return ToolResult::ok(implode("\n", $lines), ['tags' => $tags]);
    }
}
