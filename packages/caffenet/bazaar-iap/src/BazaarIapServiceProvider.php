<?php

declare(strict_types=1);

namespace Caffenet\BazaarIap;

use Caffenet\BazaarIap\Contracts\BazaarIapSettings;
use Caffenet\BazaarIap\Support\BazaarPurchaseClient;
use Caffenet\BazaarIap\Support\ConfigBazaarIapSettings;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wire-up for the Bazaar IAP gateway: config, singleton bindings, routes,
 * views, migrations and publishable assets.
 */
class BazaarIapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bazaar-iap.php', 'bazaar-iap');

        // The settings resolver is the single extension point: host apps bind
        // their own implementation (e.g. a database-backed one) and it always
        // wins — `singletonIf` never overwrites an existing binding.
        $this->app->singletonIf(BazaarIapSettings::class, function ($app) {
            return new ConfigBazaarIapSettings($app['config']);
        });

        $this->app->singleton(BazaarPurchaseClient::class, function ($app) {
            return new BazaarPurchaseClient(
                $app->make(BazaarIapSettings::class),
                $app->make(HttpFactory::class),
            );
        });

        $this->app->singleton(BazaarIap::class, function ($app) {
            return new BazaarIap(
                $app->make(BazaarIapSettings::class),
                $app->make(BazaarPurchaseClient::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'bazaar-iap');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerPublishes();

        if ((bool) config('bazaar-iap.routes.enabled', true)) {
            $this->registerRoutes();
        }
    }

    protected function registerRoutes(): void
    {
        Route::group([
            'prefix' => trim((string) config('bazaar-iap.routes.prefix', 'bazaar-iap'), '/'),
            'middleware' => (array) config('bazaar-iap.routes.middleware', ['web']),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/bazaar-iap.php');
        });
    }

    protected function registerPublishes(): void
    {
        $this->publishes([
            __DIR__.'/../config/bazaar-iap.php' => config_path('bazaar-iap.php'),
        ], 'bazaar-iap-config');

        $this->publishes([
            __DIR__.'/../resources/assets/js/bazaar-iap.js' => public_path('js/bazaar-iap.js'),
        ], 'bazaar-iap-assets');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'bazaar-iap-migrations');
    }
}
