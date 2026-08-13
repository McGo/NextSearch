<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A user's own API keys. Everything here is scoped to the signed-in user, and
 * only over the session — a key cannot be used to mint another one.
 */
class ApiTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->apiTokens()
            ->latest('id')
            ->get()
            ->map(fn (ApiToken $token) => $token->present());

        return response()->json(['data' => $tokens]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // Optional. Without it the key stays valid until it is revoked.
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $issued = ApiToken::issue(
            $request->user(),
            $validated['name'],
            isset($validated['expires_at']) ? new \DateTimeImmutable($validated['expires_at']) : null,
        );

        return response()->json(
            $issued['token']->present() + [
                // The one and only time. Not stored in plain anywhere.
                'token' => $issued['plain'],
            ],
            Response::HTTP_CREATED,
        );
    }

    public function destroy(Request $request, string $apiToken): Response
    {
        $request->user()->apiTokens()
            ->where('uuid', $apiToken)
            ->firstOrFail()
            ->delete();

        return response()->noContent();
    }
}
