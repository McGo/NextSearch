<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\OauthClient;
use App\Services\OAuth\OAuthServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The applications a user has connected to their account, and the button that
 * cuts one loose again.
 *
 * Runs on the session, like every other part of the profile: an MCP token must
 * not be able to grant itself another one, and must not be able to revoke
 * somebody's access either.
 */
class ConnectionController extends Controller
{
    public function __construct(private readonly OAuthServer $server) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->server->connectionsFor($request->user())]);
    }

    /**
     * Every token this user holds for this client goes. The client's
     * registration stays — someone else may be connected through it.
     */
    public function destroy(Request $request, string $client): Response
    {
        /** @var OauthClient $record */
        $record = OauthClient::query()->where('client_id', $client)->firstOrFail();

        $this->server->revokeConnection($request->user(), $record);

        return response()->noContent();
    }
}
