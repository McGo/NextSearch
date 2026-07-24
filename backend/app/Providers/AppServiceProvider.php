<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
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

        // Hinter dem Nuxt-Proxy erzeugt Laravel sonst http-URLs, obwohl der
        // Betreiber davor TLS terminiert.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
