<?php

namespace Newtxt\Laravel\Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Newtxt\Laravel\NewtxtManager;
use Newtxt\Laravel\Storage\RenderedPageSnapshotStore;
use Newtxt\Laravel\Tests\TestCase;

class TranslatedSitemapTest extends TestCase
{
    public function test_public_sitemap_contains_only_ready_translated_snapshots_and_persists_xml(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-public-sitemap-' . bin2hex(random_bytes(6));
        config()->set('app.url', 'https://example.test');
        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.site_id', 'site-one');
        config()->set('newtxt.source_language', 'en');
        config()->set('newtxt.target_languages', ['fr']);

        try {
            $snapshots = app(RenderedPageSnapshotStore::class);
            $snapshots->put([
                'siteId' => 'site-one',
                'languageCode' => 'fr',
                'path' => '/about',
                'query' => '',
                'urlMode' => 'path',
                'pageHash' => 'ready-page-hash',
                'htmlHash' => 'ready-html-hash',
                'pageHashVersion' => 'newtxt-laravel-v4-runtime-rendering',
                'translationReady' => true,
                'html' => '<html><head><title>Bonjour</title></head><body><main>Bonjour page</main></body></html>',
            ], true);
            $snapshots->put([
                'siteId' => 'site-one',
                'languageCode' => 'fr',
                'path' => '/draft',
                'query' => '',
                'urlMode' => 'path',
                'pageHash' => 'draft-page-hash',
                'htmlHash' => 'draft-html-hash',
                'pageHashVersion' => 'newtxt-laravel-v4-runtime-rendering',
                'translationReady' => false,
                'html' => '<html><head><title>Draft</title></head><body><main>Draft page</main></body></html>',
            ], true);

            $response = $this->get('/translate-sitemap.xml');

            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
            $response->assertHeader('X-Content-Type-Options', 'nosniff');

            $document = new DOMDocument();
            $this->assertTrue($document->loadXML($response->getContent()));
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('sitemap', 'http://www.sitemaps.org/schemas/sitemap/0.9');

            $locations = [];
            foreach ($xpath->query('//sitemap:url/sitemap:loc') ?: [] as $node) {
                $locations[] = $node->textContent;
            }

            $this->assertSame(['https://example.test/fr/about'], $locations);
            $this->assertFileExists($storagePath . '/sitemaps/translate-sitemap.xml');
            $this->assertSame(
                $response->getContent(),
                (new Filesystem())->get($storagePath . '/sitemaps/translate-sitemap.xml'),
            );

            $etag = (string) $response->headers->get('ETag');
            $this->withHeader('If-None-Match', $etag)
                ->get('/translate-sitemap.xml')
                ->assertStatus(304);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }

    public function test_ready_service_render_refreshes_local_sitemap_automatically(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-auto-sitemap-' . bin2hex(random_bytes(6));
        config()->set('app.url', 'https://example.test');
        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 86400);

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => 'site-two',
                'sourceLanguage' => 'en',
                'defaultUrlMode' => 'path',
                'translationMode' => 'seo',
                'cacheTranslatedPages' => true,
                'targetLanguages' => [
                    ['languageCode' => 'fr', 'displayName' => 'French'],
                ],
            ]),
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => Http::response([
                'siteId' => 'site-two',
                'languageCode' => 'fr',
                'path' => '/about',
                'urlMode' => 'path',
                'translatedUrl' => 'https://example.test/fr/about',
                'html' => '<html data-cservice-rendered="translated-html" data-cservice-rendered-language="fr"><head><title>Bonjour</title></head><body><main><h1>Bonjour translated page</h1></main></body></html>',
            ]),
        ]);

        try {
            $rendered = app(NewtxtManager::class)->rememberRenderedPage('fr', '/about');

            $this->assertIsArray($rendered);
            $this->assertTrue((bool) ($rendered['translationReady'] ?? false));
            $this->assertFileExists($storagePath . '/sitemaps/translate-sitemap.xml');
            $this->assertStringContainsString(
                '<loc>https://example.test/fr/about</loc>',
                (new Filesystem())->get($storagePath . '/sitemaps/translate-sitemap.xml'),
            );
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }

    public function test_refresh_command_preserves_robots_and_does_not_duplicate_directive(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-command-sitemap-' . bin2hex(random_bytes(6));
        $publicPath = sys_get_temp_dir() . '/newtxt-laravel-command-public-' . bin2hex(random_bytes(6));
        $files = new Filesystem();
        $files->ensureDirectoryExists($publicPath);
        $files->put($publicPath . '/robots.txt', "User-agent: *\nDisallow: /private\n");

        $this->app->usePublicPath($publicPath);
        config()->set('app.url', 'https://example.com');
        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.site_id', 'site-three');

        try {
            $this->artisan('newtxt:sitemap-refresh', ['--register-robots' => true])
                ->assertSuccessful();
            $this->artisan('newtxt:sitemap-refresh', ['--register-robots' => true])
                ->assertSuccessful();

            $robots = $files->get($publicPath . '/robots.txt');
            $this->assertStringContainsString('Disallow: /private', $robots);
            $this->assertSame(1, substr_count($robots, 'Sitemap: https://example.com/translate-sitemap.xml'));
        } finally {
            $files->deleteDirectory($storagePath);
            $files->deleteDirectory($publicPath);
        }
    }

    public function test_disabled_sitemap_returns_not_found(): void
    {
        config()->set('newtxt.sitemap_enabled', false);

        $this->get('/translate-sitemap.xml')->assertNotFound();
    }
}
