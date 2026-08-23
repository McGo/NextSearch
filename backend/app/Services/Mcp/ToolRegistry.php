<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Tools\GetDocumentTool;
use App\Services\Mcp\Tools\ListTagsTool;
use App\Services\Mcp\Tools\SearchDocumentsTool;
use App\Services\Mcp\Tools\TagDocumentsTool;
use App\Services\Mcp\Tools\Tool;
use Illuminate\Contracts\Container\Container;

/**
 * What the server offers. Four tools, in the order a client should read them:
 * find something, look at it, learn the vocabulary, mark it.
 *
 * Kept short on purpose. Every tool costs the model context before it has done
 * anything, and a list of twenty makes it pick worse, not better.
 */
class ToolRegistry
{
    /** @var list<class-string<Tool>> */
    private const TOOLS = [
        SearchDocumentsTool::class,
        GetDocumentTool::class,
        ListTagsTool::class,
        TagDocumentsTool::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<Tool>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => $this->container->make($class), self::TOOLS);
    }

    public function find(string $name): ?Tool
    {
        foreach ($this->all() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * The tool list as the protocol wants it. `annotations` are hints for the
     * client: a read-only tool need not be confirmed with the user every time.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        return array_map(fn (Tool $tool) => [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => trim($tool->description()),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => [
                'title' => $tool->title(),
                'readOnlyHint' => $tool->readOnly(),
                // Nothing here deletes anything. Removing a tag takes an
                // assignment off, not the document and not the tag.
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ], $this->all());
    }
}
