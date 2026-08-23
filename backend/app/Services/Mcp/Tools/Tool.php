<?php

namespace App\Services\Mcp\Tools;

use App\Services\Mcp\McpIdentity;
use App\Services\Mcp\ToolResult;

/**
 * One tool the MCP server offers. The descriptions are written for a model, not
 * for a manual: they say what the tool does, what it costs and when to reach
 * for another one — that is what decides whether it gets called at the right
 * moment.
 */
interface Tool
{
    /** The identifier the client calls. Stable; renaming breaks saved prompts. */
    public function name(): string;

    /** Short human-readable name, shown in the client's tool list. */
    public function title(): string;

    public function description(): string;

    /** The scope a token needs. See App\Services\OAuth\Scopes. */
    public function scope(): string;

    /** Whether the tool only reads. Clients use it to decide what to confirm. */
    public function readOnly(): bool;

    /**
     * JSON Schema for the arguments.
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments, McpIdentity $identity): ToolResult;
}
