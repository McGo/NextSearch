<?php

namespace App\Providers;

use App\Models\Document;
use App\Services\Search\SearchIndex;
use Illuminate\Database\Eloquent\Model;
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
}
