<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Models\Document;
use App\Models\User;
use App\Services\Search\SearchIndex;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Meilisearch\Client as MeilisearchClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MeilisearchClient::class, fn () => new MeilisearchClient(
            (string) config('meilisearch.host'),
            config('meilisearch.key'),
        ));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::unguard(false);

        $this->registerApiTokenGuard();
        $this->registerApiRateLimit();
        $this->registerMcpRateLimits();

        // Behind the Nuxt proxy Laravel would otherwise build http URLs, even
        // though the operator terminates TLS in front of it.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Documents are bound by uuid. A hit whose row is gone from the database
        // but still lingers in the search index would 404 with a raw error — so
        // heal it: drop it from the index (it won't show up again) and report a
        // clean "no longer available" instead.
        Route::bind('document', function (string $uuid) {
            $document = Document::query()->where('uuid', $uuid)->first();

            if ($document === null) {
                try {
                    app(SearchIndex::class)->forget([$uuid]);
                } catch (\Throwable) {
                    // Best-effort cleanup — still report the document as gone.
                }
                abort(404, __('nextsearch.document.gone'));
            }

            return $document;
        });
    }

    /**
     * Authentication for /api/v1 — a personal key in the Authorization header
     * instead of the session cookie the interface uses.
     *
     * A key carries no permissions of its own: the request runs as its owner
     * and sees exactly what that person sees in the interface.
     */
    private function registerApiTokenGuard(): void
    {
        Auth::viaRequest('api-token', function (Request $request): ?User {
            $plain = $request->bearerToken();

            if (! is_string($plain) || $plain === '') {
                return null;
            }

            // Looked up by hash, so the plain text never has to be compared —
            // and never has to be stored.
            $token = ApiToken::query()
                ->with('user')
                ->where('token_hash', ApiToken::hash($plain))
                ->first();

            if ($token === null || $token->hasExpired() || $token->user === null) {
                return null;
            }

            $token->touchLastUsed();

            return $token->user;
        });
    }

    /**
     * The rate limit /api/v1 runs on. Counted per key owner, falling back to
     * the caller's address while a request is still unauthenticated.
     */
    private function registerApiRateLimit(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('nextsearch.api.rate_limit')
        )->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Two limits for the MCP side.
     *
     * The endpoint itself is counted per user, and more generously than
     * /api/v1: an assistant working through a question fires a handful of tool
     * calls in a row, and being throttled mid-thought is worse than useless.
     *
     * Registration is counted per address, because it is the one endpoint here
     * that a stranger can reach and that writes a row.
     */
    private function registerMcpRateLimits(): void
    {
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(
            (int) config('nextsearch.mcp.rate_limit')
        )->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('oauth-register', fn (Request $request) => Limit::perHour(
            (int) config('nextsearch.mcp.oauth.registration_rate_limit')
        )->by($request->ip()));
    }
}
