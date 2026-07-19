<?php

namespace Newtxt\Laravel\Tests\Feature;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Newtxt\Laravel\NewtxtManager;
use Newtxt\Laravel\Storage\RenderedPageSnapshotStore;
use Newtxt\Laravel\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

class SeoMiddlewareIntegrationTest extends TestCase
{
    public function test_seo_middleware_noindexes_translated_fallback_without_server_credentials(): void
    {
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'placeholder-private-key');
        config()->set('newtxt.api_key', 'replace-with-dashboard-api-key');
        config()->set('newtxt.target_languages', 'fr');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('app.url', 'https://example.test');

        Http::fake();

        Route::middleware(['web', 'newtxt.render'])->get('/{path?}', function () {
            return response('<html><head><title>Source</title></head><body><main>Source only</main></body></html>', 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        })->where('path', '.*');

        $response = $this->get('/fr/about');

        $response->assertOk();
        $response->assertHeaderMissing('X-NewTXT-Cache');
        $response->assertHeader('X-NewTXT-Translation-Status', 'incomplete');
        $response->assertHeader('X-Robots-Tag', 'noindex, follow');
        $response->assertSee('Source only', false);
        $response->assertSee('<link rel="canonical" href="https://example.test/fr/about">', false);
        $response->assertSee('<meta name="robots" content="noindex,follow">', false);
        $response->assertDontSee('hreflang=', false);

        Http::assertNothingSent();
    }

    public function test_trusted_rewritten_language_is_noindexed_when_account_settings_are_unavailable(): void
    {
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'placeholder-private-key');
        config()->set('newtxt.api_key', 'replace-with-dashboard-api-key');
        config()->set('newtxt.target_languages', []);
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('app.url', 'https://example.test');

        Http::fake();

        Route::middleware(['web', RewritesLanguagePrefixForNewtxtTest::class, 'newtxt.render'])->get('/{path?}', function () {
            return response('<html><head><title>Source</title></head><body><main><h1>Source heading</h1><p>Source fallback content.</p></main></body></html>', 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        })->where('path', '.*');

        $response = $this->get('/fr/about');

        $response->assertOk();
        $response->assertHeader('X-NewTXT-Translation-Status', 'incomplete');
        $response->assertHeader('X-Robots-Tag', 'noindex, follow');
        $response->assertSee('<meta name="robots" content="noindex,follow">', false);

        Http::assertNothingSent();
    }

    public function test_sitemap_entries_do_not_publish_languages_without_ready_snapshots(): void
    {
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'placeholder-private-key');
        config()->set('newtxt.api_key', 'replace-with-dashboard-api-key');
        config()->set('newtxt.target_languages', 'fr,de');
        config()->set('newtxt.account_settings_cache_ttl', 0);

        Http::fake();

        $entries = app(NewtxtManager::class)->sitemapEntries([
            [
                'loc' => 'https://example.test/about',
                'lastmod' => '2026-07-15T00:00:00+00:00',
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ],
        ], 'https://example.test', ['urlMode' => 'path']);

        $locations = array_column($entries, 'loc');

        $this->assertContains('https://example.test/about', $locations);
        $this->assertNotContains('https://example.test/fr/about', $locations);
        $this->assertNotContains('https://example.test/de/about', $locations);

        Http::assertNothingSent();
    }

    public function test_sitemap_entries_keep_ready_snapshots_when_account_disables_targets_by_default(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-disabled-sitemap-' . bin2hex(random_bytes(6));
        $siteId = '00000000-0000-0000-0000-000000000050';

        config()->set('app.url', 'https://example.test');
        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.target_languages', 'fr');
        config()->set('newtxt.account_settings_cache_ttl', 0);

        try {
            Http::fake([
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                    'siteId' => $siteId,
                    'publicKey' => 'public-site-key',
                    'sourceLanguage' => 'en',
                    'defaultUrlMode' => 'path',
                    'navigationMode' => 'redirect',
                    'translationMode' => 'seo',
                    'cacheTranslatedPages' => true,
                    'widgetSettings' => [
                        'enabled' => true,
                        'translationMode' => 'seo',
                        'defaultUrlMode' => 'path',
                        'cacheTranslatedPages' => true,
                    ],
                    'targetLanguages' => [
                        ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false, 'isActive' => false],
                    ],
                ]),
            ]);

            app(RenderedPageSnapshotStore::class)->put([
                'siteId' => $siteId,
                'languageCode' => 'fr',
                'path' => '/about',
                'urlMode' => 'path',
                'query' => '',
                'pageHash' => 'ready-fr-about',
                'pageHashVersion' => 'newtxt-laravel-v3',
                'translationReady' => true,
                'htmlHash' => 'ready-html-hash',
                'html' => '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr" lang="fr"><head><title>Bonjour</title><meta name="description" content="Bonjour description"><link rel="canonical" href="https://example.test/fr/about"><link rel="alternate" href="https://example.test/fr/about" hreflang="fr"></head><body><main><h1>Bonjour</h1><p>Bonjour translated page.</p></main></body></html>',
            ], true);

            $entries = app(NewtxtManager::class)->sitemapEntries([
                [
                    'loc' => 'https://example.test/about',
                    'lastmod' => '2026-07-15T00:00:00+00:00',
                    'changefreq' => 'weekly',
                    'priority' => '0.7',
                ],
            ], 'https://example.test', ['urlMode' => 'path']);

            $locations = array_column($entries, 'loc');

            $this->assertContains('https://example.test/about', $locations);
            $this->assertContains('https://example.test/fr/about', $locations);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }

    public function test_sitemap_entries_auto_remove_unavailable_snapshots_when_enabled(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-auto-clean-sitemap-' . bin2hex(random_bytes(6));
        $siteId = '00000000-0000-0000-0000-000000000051';

        config()->set('app.url', 'https://example.test');
        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.target_languages', 'fr');
        config()->set('newtxt.account_settings_cache_ttl', 0);

        try {
            Http::fake([
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                    'siteId' => $siteId,
                    'publicKey' => 'public-site-key',
                    'sourceLanguage' => 'en',
                    'defaultUrlMode' => 'path',
                    'navigationMode' => 'redirect',
                    'translationMode' => 'seo',
                    'cacheTranslatedPages' => true,
                    'widgetSettings' => [
                        'enabled' => true,
                        'translationMode' => 'seo',
                        'defaultUrlMode' => 'path',
                        'cacheTranslatedPages' => true,
                        'autoRemoveUnavailableSitemapPages' => true,
                    ],
                    'targetLanguages' => [
                        ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false, 'isActive' => false],
                    ],
                ]),
            ]);

            app(RenderedPageSnapshotStore::class)->put([
                'siteId' => $siteId,
                'languageCode' => 'fr',
                'path' => '/about',
                'urlMode' => 'path',
                'query' => '',
                'pageHash' => 'ready-fr-about',
                'pageHashVersion' => 'newtxt-laravel-v3',
                'translationReady' => true,
                'htmlHash' => 'ready-html-hash',
                'html' => '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr" lang="fr"><head><title>Bonjour</title><meta name="description" content="Bonjour description"><link rel="canonical" href="https://example.test/fr/about"><link rel="alternate" href="https://example.test/fr/about" hreflang="fr"></head><body><main><h1>Bonjour</h1><p>Bonjour translated page.</p></main></body></html>',
            ], true);

            $entries = app(NewtxtManager::class)->sitemapEntries([
                [
                    'loc' => 'https://example.test/about',
                    'lastmod' => '2026-07-15T00:00:00+00:00',
                    'changefreq' => 'weekly',
                    'priority' => '0.7',
                ],
            ], 'https://example.test', ['urlMode' => 'path']);

            $locations = array_column($entries, 'loc');

            $this->assertContains('https://example.test/about', $locations);
            $this->assertNotContains('https://example.test/fr/about', $locations);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }

    public function test_rendered_page_sitemap_entries_skip_snapshots_without_stored_html(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-missing-html-sitemap-' . bin2hex(random_bytes(6));
        $siteId = '00000000-0000-0000-0000-000000000060';

        config()->set('app.url', 'https://example.test');
        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.site_id', $siteId);
        config()->set('newtxt.target_languages', 'fr');

        try {
            app(RenderedPageSnapshotStore::class)->put([
                'siteId' => $siteId,
                'languageCode' => 'fr',
                'path' => '/about',
                'urlMode' => 'path',
                'query' => '',
                'pageHash' => 'ready-fr-without-html',
                'pageHashVersion' => 'newtxt-laravel-v3',
                'translationReady' => true,
                'htmlHash' => 'ready-html-hash',
                'html' => '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr"><head><title>Bonjour</title></head><body><main><h1>Bonjour</h1><p>Bonjour translated page.</p></main></body></html>',
            ], false);

            $entries = app(NewtxtManager::class)->renderedPageSitemapEntries('https://example.test', ['urlMode' => 'path']);

            $this->assertSame([], $entries);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }

    public function test_env_config_widget_directive_and_seo_middleware_work_together(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-seo-' . bin2hex(random_bytes(6));

        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 0);
        config()->set('app.url', 'https://example.test');
        $this->beforeApplicationDestroyed(static function () use ($storagePath): void {
            (new Filesystem())->deleteDirectory($storagePath);
        });

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000000',
                'publicKey' => 'public-site-key',
                'sourceLanguage' => 'en',
                'defaultUrlMode' => 'path',
                'navigationMode' => 'redirect',
                'translationMode' => 'seo',
                'cacheTranslatedPages' => true,
                'injectSeoMetadata' => true,
                'seoRobots' => 'index,follow',
                'widgetSettings' => [
                    'enabled' => true,
                    'defaultUrlMode' => 'path',
                    'translationMode' => 'seo',
                    'cacheTranslatedPages' => true,
                ],
                'targetLanguages' => [
                    ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false],
                    ['languageCode' => 'de', 'displayName' => 'German', 'isDefault' => false],
                ],
            ]),
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000000',
                'languageCode' => 'fr',
                'path' => '/about',
                'urlMode' => 'path',
                'originalUrl' => 'https://example.test/about',
                'translatedUrl' => 'https://example.test/fr/about',
                'fromCache' => false,
                'cacheEnabled' => true,
                'cachedAtUtc' => '2026-07-14T00:00:00Z',
                'seoTitle' => 'Bonjour title',
                'seoDescription' => 'Bonjour translated description',
                'tableOfContents' => [
                    ['title' => 'Bonjour overview'],
                    ['title' => 'Bonjour details'],
                ],
                'html' => '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr"><head><title>Bonjour</title></head><body><main><h1>Bonjour</h1><p>Localized translated page content.</p></main></body></html>',
            ]),
        ]);

        Route::middleware(['web', 'newtxt.render'])->get('/{path?}', function () {
            return response('<html><head><title>Source</title><meta name="description" content="Source description"></head><body><main><h1>Source overview</h1><h2>Source details</h2>Source</main></body></html>', 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        })->where('path', '.*');

        $widget = Blade::render('@newtxtWidget()');
        $this->assertStringContainsString('src="https://cdn.newtxt.io/widget/v1/loader.js"', $widget);
        $this->assertStringContainsString('data-site-key="public-site-key"', $widget);
        $this->assertStringNotContainsString('private-site-key', $widget);
        $this->assertStringNotContainsString('api-site-key', $widget);

        $sourceResponse = $this->get('/about');

        $sourceResponse->assertOk();
        $sourceResponse->assertSee('Source', false);
        $sourceResponse->assertSee('<link rel="canonical" href="https://example.test/about">', false);
        $sourceResponse->assertSee('<link rel="alternate" href="https://example.test/about" hreflang="en">', false);
        $sourceResponse->assertSee('<link rel="alternate" href="https://example.test/about" hreflang="x-default">', false);
        $sourceResponse->assertDontSee('hreflang="fr"', false);
        $sourceResponse->assertDontSee('hreflang="de"', false);
        $sourceResponse->assertSee('<meta property="og:title" content="Source">', false);
        $sourceResponse->assertSee('<meta property="og:description" content="Source description">', false);
        $sourceResponse->assertSee('<meta name="newtxt:table-of-contents" content="Source overview | Source details">', false);

        $response = $this->get('/fr/about');

        $response->assertOk();
        $response->assertHeader('X-NewTXT-Translation-Status', 'ready');
        $response->assertSee('Bonjour', false);
        $response->assertDontSee('Source', false);
        $response->assertSee('<link rel="canonical" href="https://example.test/fr/about">', false);
        $response->assertSee('<link rel="alternate" href="https://example.test/about" hreflang="en">', false);
        $response->assertSee('<link rel="alternate" href="https://example.test/about" hreflang="x-default">', false);
        $response->assertSee('<link rel="alternate" href="https://example.test/fr/about" hreflang="fr">', false);
        $response->assertDontSee('hreflang="de"', false);
        $response->assertSee('<meta name="robots" content="index,follow">', false);
        $response->assertSee('<title>Bonjour title</title>', false);
        $response->assertSee('<meta property="og:title" content="Bonjour title">', false);
        $response->assertSee('<meta name="description" content="Bonjour translated description">', false);
        $response->assertSee('<meta name="newtxt:table-of-contents" content="Bonjour overview | Bonjour details">', false);

        $sourceAfterReadyRender = $this->get('/about');
        $sourceAfterReadyRender->assertSee('<link rel="alternate" href="https://example.test/fr/about" hreflang="fr">', false);
        $sourceAfterReadyRender->assertDontSee('hreflang="de"', false);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && str_contains((string) $request->url(), '/integrations/laravel/pages/render')
                && is_array($payload)
                && $payload['languageCode'] === 'fr'
                && $payload['path'] === '/about'
                && $payload['urlMode'] === 'path'
                && $request->hasHeader('X-NewTXT-Api-Key', 'api-site-key')
                && $request->hasHeader('X-NewTXT-Public-Key', 'public-site-key')
                && $request->hasHeader('X-NewTXT-Signature')
                && !$request->hasHeader('X-NewTXT-Private-Key');
        });
    }

    public function test_translated_render_replaces_source_head_metadata_from_captured_context(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-source-seo-' . bin2hex(random_bytes(6));

        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 0);
        config()->set('app.url', 'https://example.test');
        $this->beforeApplicationDestroyed(static function () use ($storagePath): void {
            (new Filesystem())->deleteDirectory($storagePath);
        });

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000040',
                'publicKey' => 'public-site-key',
                'sourceLanguage' => 'en',
                'defaultUrlMode' => 'path',
                'translationMode' => 'seo',
                'targetLanguages' => [
                    ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false],
                ],
            ]),
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000040',
                'languageCode' => 'fr',
                'path' => '/about',
                'urlMode' => 'path',
                'translatedUrl' => 'https://example.test/fr/about',
                'fromCache' => false,
                'html' => '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr"><head><title>Source title</title><meta name="description" content="Source description"><meta name="keywords" content="source, seo"><meta property="og:title" content="Source OG title"></head><body><main><h1>Bonjour</h1><p>Contenu localise avec assez de detail pour une meta description.</p></main></body></html>',
            ]),
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/translations*' => Http::response([
                'nodes' => [],
            ]),
        ]);

        Route::middleware(['web', 'newtxt.render'])->get('/{path?}', function () {
            return response('<html><head><title>Source title</title><meta name="description" content="Source description"><meta name="keywords" content="source, seo"><meta property="og:title" content="Source OG title"></head><body><main><h1>Source heading</h1><p>Source page body.</p></main></body></html>', 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        })->where('path', '.*');

        $sourceResponse = $this->get('/about');
        $sourceResponse->assertOk();

        $manager = app(NewtxtManager::class);
        $manager->putHashedTranslation('fr', 'Source title', 'Titre source');
        $manager->putHashedTranslation('fr', 'Source description', 'Description source');
        $manager->putHashedTranslation('fr', 'source, seo', 'source, referencement');
        $manager->putHashedTranslation('fr', 'Source OG title', 'Titre reseaux source');

        $response = $this->get('/fr/about');

        $response->assertOk();
        $response->assertHeader('X-NewTXT-Translation-Status', 'ready');
        $response->assertSee('<title>Titre source</title>', false);
        $response->assertSee('name="description" content="Description source"', false);
        $response->assertSee('name="keywords" content="source, referencement"', false);
        $response->assertSee('property="og:title" content="Titre reseaux source"', false);
        $response->assertDontSee('<title>Source title</title>', false);
        $response->assertDontSee('content="Source description"', false);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && str_contains((string) $request->url(), '/integrations/laravel/pages/render')
                && is_array($payload)
                && ($payload['sourceSeoMetadata']['title'] ?? null) === 'Source title'
                && ($payload['sourceSeoMetadata']['description'] ?? null) === 'Source description'
                && ($payload['sourceSeoMetadata']['keywords'] ?? null) === 'source, seo'
                && ($payload['sourceSeoMetadata']['openGraphTitle'] ?? null) === 'Source OG title';
        });
    }

    public function test_incomplete_remote_render_falls_back_to_noindex_source_html(): void
    {
        $renderRequests = 0;

        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 86400);
        config()->set('app.url', 'https://example.test');

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000040',
                'sourceLanguage' => 'en',
                'defaultUrlMode' => 'path',
                'translationMode' => 'seo',
                'targetLanguages' => [
                    ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false],
                ],
            ]),
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => function () use (&$renderRequests) {
                $renderRequests++;

                return Http::response([
                    'siteId' => '00000000-0000-0000-0000-000000000040',
                    'languageCode' => 'fr',
                    'path' => '/about',
                    'urlMode' => 'path',
                    'translatedUrl' => 'https://example.test/fr/about',
                    'translationComplete' => false,
                    'missingTargets' => 1,
                    'html' => '<html><head><title>Incomplete localized page</title></head><body><main><h1>Incomplete localized heading</h1><p>Incomplete localized content.</p></main></body></html>',
                ]);
            },
        ]);

        Route::middleware(['web', 'newtxt.render'])->get('/{path?}', function () {
            return response('<html><head><title>Source page</title><meta name="robots" content="index,follow"></head><body><main><h1>Source heading</h1><p>Source content remains visible.</p></main></body></html>', 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        })->where('path', '.*');

        $response = $this->get('/fr/about');

        $response->assertOk();
        $response->assertHeader('X-NewTXT-Translation-Status', 'incomplete');
        $response->assertHeader('X-Robots-Tag', 'noindex, follow');
        $response->assertSee('Source content remains visible.', false);
        $response->assertDontSee('Incomplete localized content.', false);
        $response->assertSee('<meta name="robots" content="noindex,follow">', false);

        $secondResponse = $this->get('/fr/about');
        $secondResponse->assertHeader('X-NewTXT-Translation-Status', 'incomplete');
        $this->assertSame(2, $renderRequests);
    }

    public function test_rendered_page_snapshot_rehydrates_local_cache_without_remote_render(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-snapshots-' . bin2hex(random_bytes(6));
        $renderRequests = 0;
        $settings = [
            'siteId' => '00000000-0000-0000-0000-000000000010',
            'publicKey' => 'public-site-key',
            'sourceLanguage' => 'en',
            'defaultUrlMode' => 'path',
            'navigationMode' => 'redirect',
            'translationMode' => 'seo',
            'cacheTranslatedPages' => true,
            'injectSeoMetadata' => true,
            'seoRobots' => 'index,follow',
            'targetLanguages' => [
                ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false],
            ],
        ];

        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 86400);
        config()->set('newtxt.refresh_rendered_pages_after_local_hit', false);

        Route::middleware(['web', 'newtxt.render'])->get('/{path?}', function () {
            return response('<html><head><title>Source</title></head><body><main>Source</main></body></html>', 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        })->where('path', '.*');

        try {
            Http::fake([
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response($settings),
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => function () use (&$renderRequests) {
                    $renderRequests++;

                    return Http::response([
                        'siteId' => '00000000-0000-0000-0000-000000000010',
                        'languageCode' => 'fr',
                        'path' => '/about',
                        'urlMode' => 'path',
                        'translatedUrl' => 'https://example.test/fr/about',
                        'fromCache' => false,
                        'html' => '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr"><head><title>Bonjour</title></head><body><main><h1>Bonjour</h1><p>Bonjour from local snapshot</p></main></body></html>',
                    ]);
                },
            ]);

            $firstResponse = $this->get('/fr/about?utm=campaign');
            $firstResponse->assertOk();
            $firstResponse->assertSee('Bonjour from local snapshot', false);
            $this->assertSame(1, $renderRequests);

            Cache::flush();

            Http::fake([
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response($settings),
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => function () use (&$renderRequests) {
                    $renderRequests++;

                    return Http::response([
                        'siteId' => '00000000-0000-0000-0000-000000000010',
                        'languageCode' => 'fr',
                        'path' => '/about',
                        'urlMode' => 'path',
                        'translatedUrl' => 'https://example.test/fr/about',
                        'fromCache' => false,
                        'html' => '<html><body>Unexpected remote render</body></html>',
                    ]);
                },
            ]);

            $secondResponse = $this->get('/fr/about?utm=campaign');
            $secondResponse->assertOk();
            $secondResponse->assertHeader('X-NewTXT-Cache', 'local-hit');
            $secondResponse->assertSee('Bonjour from local snapshot', false);
            $secondResponse->assertDontSee('Unexpected remote render', false);
            $this->assertSame(1, $renderRequests);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }

    public function test_local_rendered_page_hit_refreshes_remote_snapshot_after_response(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-refresh-' . bin2hex(random_bytes(6));
        $renderRequests = 0;
        $settings = [
            'siteId' => '00000000-0000-0000-0000-000000000011',
            'publicKey' => 'public-site-key',
            'sourceLanguage' => 'en',
            'defaultUrlMode' => 'path',
            'navigationMode' => 'redirect',
            'translationMode' => 'seo',
            'cacheTranslatedPages' => true,
            'targetLanguages' => [
                ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false],
            ],
        ];

        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 86400);
        config()->set('newtxt.rendered_page_refresh_ttl', 300);

        Route::middleware(['web', 'newtxt.render'])->get('/{path?}', function () {
            return response('<html><head><title>Source</title></head><body><main>Source</main></body></html>', 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        })->where('path', '.*');

        try {
            Http::fake([
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response($settings),
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => function () use (&$renderRequests) {
                    $renderRequests++;

                    return Http::response([
                        'siteId' => '00000000-0000-0000-0000-000000000011',
                        'languageCode' => 'fr',
                        'path' => '/about',
                        'urlMode' => 'path',
                        'translatedUrl' => 'https://example.test/fr/about',
                        'fromCache' => false,
                        'html' => $renderRequests === 1
                            ? '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr"><head><title>Bonjour</title></head><body><main><h1>Bonjour</h1><p>Local snapshot copy</p></main></body></html>'
                            : '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr"><head><title>Bonjour updated</title></head><body><main><h1>Bonjour</h1><p>Refreshed snapshot copy</p></main></body></html>',
                    ]);
                },
            ]);

            $firstResponse = $this->get('/fr/about');
            $firstResponse->assertOk();
            $firstResponse->assertSee('Local snapshot copy', false);
            $this->assertSame(1, $renderRequests);

            Cache::flush();

            $secondResponse = $this->get('/fr/about');
            $secondResponse->assertOk();
            $secondResponse->assertHeader('X-NewTXT-Cache', 'local-hit');
            $secondResponse->assertSee('Local snapshot copy', false);
            $secondResponse->assertDontSee('Refreshed snapshot copy', false);
            $this->assertSame(2, $renderRequests);

            config()->set('newtxt.refresh_rendered_pages_after_local_hit', false);

            $thirdResponse = $this->get('/fr/about');
            $thirdResponse->assertOk();
            $thirdResponse->assertHeader('X-NewTXT-Cache', 'local-hit');
            $thirdResponse->assertSee('Refreshed snapshot copy', false);
            $thirdResponse->assertDontSee('Local snapshot copy', false);
            $this->assertSame(2, $renderRequests);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }

    public function test_unsupported_query_parameters_skip_translated_ssr_without_remote_render(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-query-skip-' . bin2hex(random_bytes(6));
        $renderRequests = 0;

        config()->set('app.url', 'https://example.test');
        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000040',
                'publicKey' => 'public-site-key',
                'sourceLanguage' => 'en',
                'defaultUrlMode' => 'path',
                'translationMode' => 'seo',
                'targetLanguages' => [
                    ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false],
                ],
            ]),
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => function () use (&$renderRequests) {
                $renderRequests++;

                return Http::response([
                    'html' => '<html><body>Unexpected translated query page</body></html>',
                ]);
            },
        ]);

        try {
            Route::middleware(['web', 'newtxt.render'])->get('/{path?}', function () {
                return response('<html><head><title>Source</title></head><body><main>Source query fallback</main></body></html>', 200)
                    ->header('Content-Type', 'text/html; charset=UTF-8');
            })->where('path', '.*');

            $response = $this->get('/fr/vehicles?city=tbilisi');

            $response->assertOk();
            $response->assertHeaderMissing('X-NewTXT-Cache');
            $response->assertHeader('X-NewTXT-Translation-Status', 'incomplete');
            $response->assertSee('Source query fallback', false);
            $response->assertDontSee('Unexpected translated query page', false);
            $this->assertSame(0, $renderRequests);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }

    public function test_page_rule_can_disable_local_hit_refresh(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-refresh-disabled-' . bin2hex(random_bytes(6));
        $renderRequests = 0;
        $settings = [
            'siteId' => '00000000-0000-0000-0000-000000000012',
            'publicKey' => 'public-site-key',
            'sourceLanguage' => 'en',
            'defaultUrlMode' => 'path',
            'navigationMode' => 'redirect',
            'translationMode' => 'seo',
            'cacheTranslatedPages' => true,
            'targetLanguages' => [
                ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false],
            ],
            'pageRules' => [
                [
                    'path' => '/about',
                    'isExcludedFromSitemap' => false,
                    'redirectTargetUrl' => null,
                    'disableContentRefresh' => true,
                ],
            ],
        ];

        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 86400);

        Route::middleware(['web', 'newtxt.render'])->get('/{path?}', function () {
            return response('<html><head><title>Source</title></head><body><main>Source</main></body></html>', 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        })->where('path', '.*');

        try {
            Http::fake([
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response($settings),
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => function () use (&$renderRequests) {
                    $renderRequests++;

                    return Http::response([
                        'siteId' => '00000000-0000-0000-0000-000000000012',
                        'languageCode' => 'fr',
                        'path' => '/about',
                        'urlMode' => 'path',
                        'translatedUrl' => 'https://example.test/fr/about',
                        'fromCache' => false,
                        'html' => '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr"><head><title>Bonjour</title></head><body><main><h1>Bonjour</h1><p>Frozen snapshot copy</p></main></body></html>',
                    ]);
                },
            ]);

            $firstResponse = $this->get('/fr/about');
            $firstResponse->assertOk();
            $this->assertSame(1, $renderRequests);

            Cache::flush();

            $secondResponse = $this->get('/fr/about');
            $secondResponse->assertOk();
            $secondResponse->assertHeader('X-NewTXT-Cache', 'local-hit');
            $secondResponse->assertSee('Frozen snapshot copy', false);
            $this->assertSame(1, $renderRequests);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }

    public function test_rewritten_language_attributes_serve_translated_html_from_source_path(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-rewritten-' . bin2hex(random_bytes(6));

        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 0);
        config()->set('app.url', 'https://example.test');

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000030',
                'publicKey' => 'public-site-key',
                'sourceLanguage' => 'en',
                'defaultUrlMode' => 'path',
                'translationMode' => 'seo',
                'targetLanguages' => [
                    ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false],
                ],
            ]),
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000030',
                'languageCode' => 'fr',
                'path' => '/about',
                'urlMode' => 'path',
                'translatedUrl' => 'https://example.test/fr/about',
                'fromCache' => false,
                'html' => '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr"><head><title>Bonjour rewritten</title></head><body><main><h1>Bonjour rewritten page</h1></main></body></html>',
            ]),
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/translations*' => Http::response([
                'path' => '/about',
                'urlMode' => 'path',
                'translatedUrl' => 'https://example.test/fr/about',
                'nodes' => [
                    [
                        'nodeKey' => 'main-title',
                        'nodeType' => 'text',
                        'sourceText' => 'Source rewritten page',
                        'translatedText' => 'Bonjour rewritten page',
                    ],
                ],
            ]),
        ]);

        try {
            Route::middleware(['web', RewritesLanguagePrefixForNewtxtTest::class, 'newtxt.render'])->get('/{path?}', function () {
                return response('<html><head><title>Source rewritten</title></head><body><main>Source rewritten page</main></body></html>', 200)
                    ->header('Content-Type', 'text/html; charset=UTF-8');
            })->where('path', '.*');

            $response = $this->get('/fr/about');

            $response->assertOk();
            $response->assertHeader('X-NewTXT-Cache', 'remote-miss');
            $response->assertSee('Bonjour rewritten page', false);
            $response->assertDontSee('Source rewritten page', false);

            Http::assertSent(function ($request) {
                $payload = json_decode($request->body(), true);

                return $request->method() === 'POST'
                    && str_contains((string) $request->url(), '/integrations/laravel/pages/render')
                    && is_array($payload)
                    && $payload['languageCode'] === 'fr'
                    && $payload['path'] === '/about';
            });

            $files = (new Filesystem())->glob($storagePath . '/translations/00000000-0000-0000-0000-000000000030/fr/*.json');
            $this->assertCount(1, $files);

            $stored = json_decode((new Filesystem())->get($files[0]), true);
            $this->assertSame('Source rewritten page', $stored['sourceText'] ?? null);
            $this->assertSame('Bonjour rewritten page', $stored['translatedText'] ?? null);
            $this->assertNotEmpty($stored['sourceHash'] ?? null);
            $this->assertNotEmpty($stored['translationHash'] ?? null);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }

    public function test_rendered_page_sitemap_entries_include_stored_translated_snapshots(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-sitemap-' . bin2hex(random_bytes(6));
        $settings = [
            'siteId' => '00000000-0000-0000-0000-000000000020',
            'publicKey' => 'public-site-key',
            'sourceLanguage' => 'en',
            'defaultUrlMode' => 'path',
            'navigationMode' => 'redirect',
            'translationMode' => 'seo',
            'cacheTranslatedPages' => true,
            'injectSeoMetadata' => true,
            'seoRobots' => 'index,follow',
            'targetLanguages' => [
                ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false],
                ['languageCode' => 'de', 'displayName' => 'German', 'isDefault' => false, 'isActive' => false],
            ],
        ];

        config()->set('app.url', 'https://example.test');
        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 86400);

        try {
            Http::fake([
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response($settings),
                'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => Http::response([
                    'siteId' => '00000000-0000-0000-0000-000000000020',
                    'languageCode' => 'fr',
                    'path' => '/about',
                    'urlMode' => 'path',
                    'translatedUrl' => 'https://example.test/fr/about',
                    'fromCache' => false,
                    'html' => '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr"><head><title>Bonjour</title></head><body><main><h1>Bonjour translated page</h1></main></body></html>',
                ]),
            ]);

            $rendered = app(NewtxtManager::class)->rememberRenderedPage('fr', '/about');
            $this->assertIsArray($rendered);

            $entries = app(NewtxtManager::class)->renderedPageSitemapEntries('https://example.test');

            $this->assertCount(1, $entries);
            $this->assertSame('https://example.test/fr/about', $entries[0]['loc']);
            $this->assertSame('fr', $entries[0]['languageCode']);
            $this->assertSame('/about', $entries[0]['path']);
            $this->assertSame('path', $entries[0]['urlMode']);
            $this->assertNotSame('', $entries[0]['pageHash']);
            $this->assertNotNull($entries[0]['htmlHash']);

            $sitemapEntries = app(NewtxtManager::class)->sitemapEntries([
                [
                    'loc' => 'https://example.test/blog/travel-georgia-7-days',
                    'lastmod' => '2026-07-14T00:00:00+00:00',
                    'changefreq' => 'weekly',
                    'priority' => '0.7',
                ],
            ], 'https://example.test', ['urlMode' => 'path']);
            $locations = array_column($sitemapEntries, 'loc');

            $this->assertContains('https://example.test/blog/travel-georgia-7-days', $locations);
            $this->assertNotContains('https://example.test/fr/blog/travel-georgia-7-days', $locations);
            $this->assertContains('https://example.test/fr/about', $locations);
            $this->assertNotContains('https://fr.example.test/blog/travel-georgia-7-days', $locations);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }
}

class RewritesLanguagePrefixForNewtxtTest
{
    public function handle(Request $request, Closure $next): Response
    {
        $segments = array_values(array_filter(explode('/', trim($request->getPathInfo(), '/'))));
        $languageCode = strtolower((string) ($segments[0] ?? ''));
        if ($languageCode !== 'fr') {
            return $next($request);
        }

        $sourcePath = '/' . implode('/', array_slice($segments, 1));
        $server = $request->server->all();
        $server['REQUEST_URI'] = $sourcePath;
        $server['PATH_INFO'] = $sourcePath;

        $localizedRequest = $request->duplicate(server: $server);
        $localizedRequest->attributes->set('widget_language_prefix', $languageCode);

        return $next($localizedRequest);
    }
}
