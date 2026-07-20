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
use Newtxt\Laravel\Console\PruneStorageCommand;
use Newtxt\Laravel\Console\RefreshSitemapCommand;
use Newtxt\Laravel\Console\SyncTranslationsCommand;
use Newtxt\Laravel\Html\PageHasher;
use Newtxt\Laravel\Html\SeoMetadataExtractor;
use Newtxt\Laravel\Html\SeoMetadataInjector;
use Newtxt\Laravel\Http\Controllers\NewtxtCallbackController;
use Newtxt\Laravel\Http\Controllers\NewtxtSitemapController;
use Newtxt\Laravel\Http\Middleware\ServeNewtxtTranslatedPages;
use Newtxt\Laravel\Security\CallbackSignatureVerifier;
use Newtxt\Laravel\Storage\HashedTranslationStore;
use Newtxt\Laravel\Storage\RenderedPageSnapshotStore;
use Newtxt\Laravel\Storage\TranslatedSitemapStore;

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
                (string) config('newtxt.api_key'),
                (string) config('newtxt.public_key'),
                (string) config('newtxt.private_key'),
            );
        });

        $this->app->singleton(PageHasher::class);
        $this->app->singleton(SeoMetadataExtractor::class);
        $this->app->singleton(SeoMetadataInjector::class);
        $this->app->singleton(CallbackSignatureVerifier::class, function ($app) {
            $store = config('newtxt.callback_cache_store');

            return new CallbackSignatureVerifier(
                $app->make('config'),
                $store ? $app['cache']->store($store) : $app['cache']->store(),
            );
        });
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
        $this->app->singleton(TranslatedSitemapStore::class, function ($app) {
            return new TranslatedSitemapStore(
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
                $app->make(SeoMetadataExtractor::class),
                $app->make(SeoMetadataInjector::class),
                $app->make(TranslatedSitemapStore::class),
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
        $this->registerSitemapRoute($router);

        Blade::directive('newtxtWidget', function () {
            return "<?php echo app('" . NewtxtManager::class . "')->widgetSnippet(); ?>";
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                PrewarmCommand::class,
                PruneStorageCommand::class,
                RefreshSitemapCommand::class,
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

    /**
     * Register the public translated-page sitemap endpoint.
     */
    private function registerSitemapRoute(Router $router): void
    {
        if (!(bool) config('newtxt.sitemap_enabled', true)) {
            return;
        }

        $path = trim((string) config('newtxt.sitemap_path', '/translate-sitemap.xml'));
        $path = $path === '' ? 'translate-sitemap.xml' : ltrim($path, '/');

        if (
            !str_ends_with(strtolower($path), '.xml')
            || str_contains($path, '..')
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $path) !== 1
        ) {
            $path = 'translate-sitemap.xml';
        }

        $middleware = array_values(array_filter((array) config('newtxt.sitemap_middleware', [])));
        $route = $router->get($path, NewtxtSitemapController::class)->name('newtxt.sitemap');

        if ($middleware !== []) {
            $route->middleware($middleware);
        }
    }
}
