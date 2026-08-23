<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpIdentity;
use App\Services\Mcp\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP endpoint. One route, one method: a client posts a JSON-RPC message
 * and gets one back.
 *
 * The transport is the streamable HTTP one from the specification, in its
 * simplest legitimate form. The server never opens a stream of its own — it has
 * nothing to push — so GET is answered with 405, as prescribed, and no session
 * id is handed out.
 */
class McpController extends Controller
{
    public function __invoke(Request $request, McpServer $server, McpIdentity $identity): Response
    {
        $payload = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->parseError('The request body is not valid JSON.');
        }

        // Batching is gone from the protocol as of 2025-06-18, and this server
        // never supported it. Saying so beats processing half of it.
        if (is_array($payload) && array_is_list($payload)) {
            return $this->parseError('Batched requests are not supported. Send one message per request.');
        }

        if (! is_array($payload)) {
            return $this->parseError('The request body has to be a JSON-RPC object.');
        }

        $response = $server->handle($payload, $identity);

        // A notification gets no body. 202 says it arrived and that is all
        // there is to say.
        if ($response === null) {
            return response()->noContent(Response::HTTP_ACCEPTED)
                ->header('MCP-Protocol-Version', $this->version($request));
        }

        return response()->json($response)
            ->header('MCP-Protocol-Version', $this->version($request));
    }

    /**
     * GET would open the server-to-client stream, DELETE would end a session.
     * We have neither. The specification asks for 405 in exactly this case.
     */
    public function unsupported(): JsonResponse
    {
        return response()->json([
            'jsonrpc' => McpServer::JSONRPC_VERSION,
            'id' => null,
            'error' => [
                'code' => McpServer::ERROR_METHOD_NOT_FOUND,
                'message' => 'This endpoint takes POST only — it opens no stream and keeps no session.',
            ],
        ], Response::HTTP_METHOD_NOT_ALLOWED);
    }

    /**
     * What the client announced, as far as we speak it. Echoing it back lets a
     * client notice a mismatch on the first response rather than on the first
     * unexpected field.
     */
    private function version(Request $request): string
    {
        $header = $request->header('MCP-Protocol-Version');

        /** @var list<string> $supported */
        $supported = config('nextsearch.mcp.protocol_versions');

        return is_string($header) && in_array($header, $supported, true) ? $header : $supported[0];
    }

    private function parseError(string $message): JsonResponse
    {
        return response()->json([
            'jsonrpc' => McpServer::JSONRPC_VERSION,
            'id' => null,
            'error' => ['code' => -32700, 'message' => $message],
        ], Response::HTTP_BAD_REQUEST);
    }
}
