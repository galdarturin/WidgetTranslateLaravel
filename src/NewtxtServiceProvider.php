<?php

namespace Newtxt\Laravel;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Newtxt\Laravel\Console\ClearCacheCommand;
use Newtxt\Laravel\Console\InstallCommand;
use Newtxt\Laravel\Console\PrewarmCommand;
use Newtxt\Laravel\Console\SyncTranslationsCommand;
use Newtxt\Laravel\Html\PageHasher;
use Newtxt\Laravel\Html\SeoMetadataInjector;
use Newtxt\Laravel\Http\Controllers\NewtxtCallbackController;
use Newtxt\Laravel\Http\Middleware\ServeNewtxtTranslatedPages;
use Newtxt\Laravel\Security\CallbackSignatureVerifier;
use Newtxt\Laravel\Storage\HashedTranslationStore;
use Newtxt\Laravel\Storage\RenderedPageSnapshotStore;

class NewtxtServiceProvider extends ServiceProvider
{
    /**
     * Register package services in the Laravel container.
     *
     * Bindings stay lazy so Laravel can build configuration cache and discover
     * packages without opening network connections or resolving credentials.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/newtxt.php', 'newtxt');

        $this->app->singleton(NewtxtClient::class, function ($app) {
            return new NewtxtClient(
                $app->make(HttpFactory::class),
                (string) config('newtxt.api_base_url'),
                (string) config('newtxt.api_token'),
            );
        });

        $this->app->singleton(PageHasher::class);
        $this->app->singleton(SeoMetadataInjector::class);
        $this->app->singleton(CallbackSignatureVerifier::class);
        $this->app->singleton(HashedTranslationStore::class, function ($app) {
            return new HashedTranslationStore(
                $app->make(Filesystem::class),
                $app->make('config'),
                $app->make(PageHasher::class),
            );
        });
        $this->app->singleton(RenderedPageSnapshotStore::class, function ($app) {
            return new RenderedPageSnapshotStore(
                $app->make(Filesystem::class),
                $app->make('config'),
            );
        });

        $this->app->singleton(NewtxtManager::class, function ($app) {
            return new NewtxtManager(
                $app->make(NewtxtClient::class),
                $app['cache'],
                $app->make('config'),
                $app->make(HashedTranslationStore::class),
                $app->make(RenderedPageSnapshotStore::class),
                $app->make(PageHasher::class),
                $app->make(SeoMetadataInjector::class),
            );
        });
    }

    /**
     * Publish configuration and register runtime integrations.
     *
     * The package exposes a route middleware alias instead of forcing global
     * middleware registration. Applications should attach it only to public
     * cacheable HTML routes.
     */
    public function boot(Router $router): void
    {
        $this->publishes([
            __DIR__ . '/../config/newtxt.php' => config_path('newtxt.php'),
        ], 'newtxt-config');

        $router->aliasMiddleware('newtxt.render', ServeNewtxtTranslatedPages::class);
        $this->registerCallbackRoute($router);

        Blade::directive('newtxtWidget', function () {
            return "<?php echo app('" . NewtxtManager::class . "')->widgetSnippet(); ?>";
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                PrewarmCommand::class,
                SyncTranslationsCommand::class,
                ClearCacheCommand::class,
            ]);
        }
    }

    /**
     * Register the optional signed service callback endpoint.
     */
    private function registerCallbackRoute(Router $router): void
    {
        if (!(bool) config('newtxt.callback_enabled', false)) {
            return;
        }

        $path = trim((string) config('newtxt.callback_path', '/newtxt/callback'));
        $path = $path === '' ? 'newtxt/callback' : ltrim($path, '/');
        $middleware = array_values(array_filter((array) config('newtxt.callback_middleware', [])));

        $route = $router->post($path, NewtxtCallbackController::class)->name('newtxt.callback');
        if ($middleware !== []) {
            $route->middleware($middleware);
        }
    }
}
