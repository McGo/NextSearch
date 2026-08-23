<?php

use App\Http\Controllers\Mcp\McpController;
use App\Http\Controllers\OAuth\ClientController;
use App\Http\Controllers\OAuth\MetadataController;
use App\Http\Controllers\OAuth\TokenController;
use Illuminate\Support\Facades\Route;

/*
 * The MCP server and the OAuth machinery in front of it.
 *
 * Everything here is stateless — no session, no CSRF token — because none of
 * it is called by a browser. The one exception is the consent screen, and that
 * one lives in routes/api.php on the session stack, where it belongs: a person
 * signed in to NextSearch decides there.
 *
 * The paths are written out in full. Unlike routes/v1.php this file gets no
 * prefix, because two of its routes have to sit at the root of the domain:
 * discovery documents are only looked for under /.well-known.
 *
 * Documented in docs/mcp.md.
 */

// The endpoint itself. POST only — the server opens no stream of its own, so
// GET and DELETE are answered with 405, as the transport prescribes.
Route::middleware(['auth.mcp', 'throttle:mcp'])
    ->post('/api/mcp', McpController::class);

Route::match(['get', 'delete'], '/api/mcp', [McpController::class, 'unsupported']);

// Token exchange, renewal and revocation. The client authenticates itself here,
// not the user.
Route::post('/api/oauth/token', [TokenController::class, 'issue']);
Route::post('/api/oauth/revoke', [TokenController::class, 'revoke']);

// A client registering itself, RFC 7591. Rate limited per address — it is the
// one endpoint here that anyone can reach and that writes a row.
Route::middleware('throttle:oauth-register')
    ->post('/api/oauth/register', [ClientController::class, 'register']);

/*
 * Discovery. These have to answer at the root of the public domain, so the Nuxt
 * container forwards /.well-known/oauth-* here — see
 * frontend/server/middleware/well-known.ts.
 *
 * RFC 9728 appends the resource path to the well-known prefix; RFC 8414 does
 * the same for an issuer with a path. Clients disagree about whether to bother,
 * so both the plain and the suffixed form answer, and with the same document.
 */
Route::get('/.well-known/oauth-protected-resource{path?}', [MetadataController::class, 'protectedResource'])
    ->where('path', '/.*');

Route::get('/.well-known/oauth-authorization-server{path?}', [MetadataController::class, 'authorizationServer'])
    ->where('path', '/.*');

// Some clients look for the OpenID Connect document first and fall back to the
// OAuth one. Answering both saves a round trip and a puzzled bug report.
Route::get('/.well-known/openid-configuration', [MetadataController::class, 'authorizationServer']);
