<?php

namespace App\Services\Mcp;

use Throwable;

/**
 * The Model Context Protocol, as far as NextSearch needs it: JSON-RPC 2.0 over
 * a single HTTP endpoint, tools and nothing else.
 *
 * Stateless on purpose. There is no session to keep — every request carries its
 * token, every tool call stands on its own, and a restart of the container
 * costs a client nothing. That rules out server-initiated messages, which is
 * why no SSE stream is opened and no session id handed out.
 *
 * No resources, no prompts, no sampling. A capability that is not advertised is
 * not expected either; adding one later is an addition, taking one away is not.
 */
class McpServer
{
    public const JSONRPC_VERSION = '2.0';

    /** JSON-RPC error codes, from the specification. */
    public const ERROR_INVALID_REQUEST = -32600;

    public const ERROR_METHOD_NOT_FOUND = -32601;

    public const ERROR_INVALID_PARAMS = -32602;

    public const ERROR_INTERNAL = -32603;

    public function __construct(private readonly ToolRegistry $tools) {}

    /**
     * The version this server answers a client with. If the client asks for one
     * we speak, it gets that one; otherwise ours, and the client decides
     * whether it can live with it.
     */
    public function negotiateVersion(?string $requested): string
    {
        /** @var list<string> $supported */
        $supported = config('nextsearch.mcp.protocol_versions');

        return in_array($requested, $supported, true) ? $requested : $supported[0];
    }

    /**
     * Handles one message. Returns the response, or null for a notification —
     * those get no answer, that is what makes them notifications.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    public function handle(array $message, McpIdentity $identity): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (($message['jsonrpc'] ?? null) !== self::JSONRPC_VERSION || ! is_string($method)) {
            return $this->error($id, self::ERROR_INVALID_REQUEST, 'Not a valid JSON-RPC 2.0 request.');
        }

        // A message without an id is a notification. We keep none of the state
        // they would announce, so acknowledging them is all there is to do.
        if ($id === null) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => $this->result($id, $this->initialize($params)),
                'ping' => $this->result($id, new \stdClass),
                'tools/list' => $this->result($id, ['tools' => $this->tools->describe()]),
                'tools/call' => $this->callTool($id, $params, $identity),
                default => $this->error(
                    $id,
                    self::ERROR_METHOD_NOT_FOUND,
                    sprintf('This server does not implement "%s". It offers tools only.', $method),
                ),
            };
        } catch (Throwable $e) {
            // The client gets a sentence, the operator gets the stack trace.
            report($e);

            return $this->error($id, self::ERROR_INTERNAL, 'The server ran into an internal error.');
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => $this->negotiateVersion(is_string($requested) ? $requested : null),
            'capabilities' => [
                // The list is fixed for the lifetime of a deployment, so there
                // is nothing to notify anyone about.
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => (string) config('nextsearch.mcp.server_name'),
                'version' => (string) config('nextsearch.mcp.server_version'),
            ],
            'instructions' => trim(<<<'TEXT'
            NextSearch indexes the full text of documents from Nextcloud folders and makes
            them searchable and taggable.

            Start with search_documents; every hit carries a uuid that get_document and
            tag_documents address. Before filtering or setting tags, call list_tags — tags
            are addressed by slug and only the ones listed there exist.

            Results are always limited to the folders shared with this user. A search that
            comes back empty may mean the documents are there but not shared.
            TEXT),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params, McpIdentity $identity): array
    {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (! is_string($name) || $name === '') {
            return $this->error($id, self::ERROR_INVALID_PARAMS, 'A tool call needs a name.');
        }

        $tool = $this->tools->find($name);

        if ($tool === null) {
            return $this->error($id, self::ERROR_INVALID_PARAMS, sprintf('Unknown tool "%s".', $name));
        }

        // A scope the connection was not granted is not a protocol error — the
        // model should read why it failed and say so, rather than retry.
        if (! $identity->can($tool->scope())) {
            return $this->result($id, ToolResult::error(sprintf(
                'This connection was not granted the "%s" permission. '
                .'It can be changed by reconnecting NextSearch and approving it.',
                $tool->scope(),
            ))->toArray());
        }

        try {
            $result = $tool->handle($arguments, $identity);
        } catch (Throwable $e) {
            report($e);

            $result = ToolResult::error('The tool failed. The details are in the NextSearch log.');
        }

        return $this->result($id, $result->toArray());
    }

    /**
     * @param  array<string, mixed>|object  $result
     * @return array<string, mixed>
     */
    private function result(mixed $id, array|object $result): array
    {
        return ['jsonrpc' => self::JSONRPC_VERSION, 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
