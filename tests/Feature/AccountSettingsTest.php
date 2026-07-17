<?php

namespace Newtxt\Laravel\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Newtxt\Laravel\NewtxtManager;
use Newtxt\Laravel\Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    public function test_account_settings_drive_target_language_detection(): void
    {
        config()->set('newtxt.public_key', 'public-key');
        config()->set('newtxt.private_key', 'private-key');
        config()->set('newtxt.api_key', 'api-key');

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000001',
                'publicKey' => 'public-key',
                'sourceLanguage' => 'en',
                'translationMode' => 'seo',
                'defaultUrlMode' => 'path',
                'navigationMode' => 'redirect',
                'cacheTranslatedPages' => true,
                'injectSeoMetadata' => true,
                'seoRobots' => 'index,follow',
                'widgetSettings' => [
                    'translationMode' => 'seo',
                    'defaultUrlMode' => 'path',
                    'cacheTranslatedPages' => true,
                ],
                'targetLanguages' => [
                    ['languageCode' => 'fr', 'isDefault' => false],
                ],
            ]),
        ]);

        $manager = app(NewtxtManager::class);

        $this->assertSame('fr', $manager->extractLanguageFromPath('/fr/about'));
        $this->assertNull($manager->extractLanguageFromPath('/en/about'));

        Http::assertSent(fn ($request) => $request->hasHeader('X-NewTXT-Api-Key', 'api-key')
            && $request->hasHeader('X-NewTXT-Public-Key', 'public-key')
            && $request->hasHeader('X-NewTXT-Signature')
            && !$request->hasHeader('X-NewTXT-Private-Key'));
    }

    public function test_client_translation_mode_disables_server_side_route_detection(): void
    {
        config()->set('newtxt.public_key', 'site-2-public-key');
        config()->set('newtxt.private_key', 'site-2-private-key');
        config()->set('newtxt.api_key', 'site-2-api-key');

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000002',
                'publicKey' => 'site-2-public-key',
                'sourceLanguage' => 'en',
                'defaultUrlMode' => 'path',
                'navigationMode' => 'redirect',
                'translationMode' => 'client',
                'cacheTranslatedPages' => true,
                'injectSeoMetadata' => true,
                'seoRobots' => 'index,follow',
                'widgetSettings' => [
                    'translationMode' => 'client',
                ],
                'targetLanguages' => [
                    ['languageCode' => 'fr', 'isDefault' => false],
                ],
            ]),
        ]);

        $manager = app(NewtxtManager::class);

        $this->assertNull($manager->extractLanguageFromPath('/fr/about'));
    }

    public function test_account_page_rules_filter_sitemap_entries_and_resolve_redirects(): void
    {
        config()->set('newtxt.public_key', 'site-rules-public-key');
        config()->set('newtxt.private_key', 'site-rules-private-key');
        config()->set('newtxt.api_key', 'site-rules-api-key');

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000010',
                'publicKey' => 'site-rules-public-key',
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
                'targetLanguages' => [],
                'pageRules' => [
                    [
                        'path' => '/old-page',
                        'isExcludedFromSitemap' => true,
                        'redirectTargetUrl' => '/new-page',
                    ],
                ],
            ]),
        ]);

        $manager = app(NewtxtManager::class);
        $entries = $manager->sitemapEntries([
            [
                'loc' => 'https://example.test/old-page',
                'lastmod' => '2026-07-17T00:00:00+00:00',
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ],
            [
                'loc' => 'https://example.test/keep-page',
                'lastmod' => '2026-07-17T00:00:00+00:00',
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ],
        ], 'https://example.test');

        $this->assertSame('/new-page', $manager->redirectTargetForPath('/old-page'));
        $this->assertSame(['https://example.test/keep-page'], array_column($entries, 'loc'));
    }

    public function test_widget_runtime_excluded_paths_do_not_filter_source_sitemap_entries_by_default(): void
    {
        config()->set('newtxt.excluded_paths', ['private*']);
        config()->set('newtxt.public_key', 'site-runtime-exclusions-public-key');
        config()->set('newtxt.private_key', 'site-runtime-exclusions-private-key');
        config()->set('newtxt.api_key', 'site-runtime-exclusions-api-key');

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000011',
                'publicKey' => 'site-runtime-exclusions-public-key',
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
                    'excludedPaths' => ['/private'],
                ],
                'targetLanguages' => [],
            ]),
        ]);

        $entries = app(NewtxtManager::class)->sitemapEntries([
            [
                'loc' => 'https://example.test/private',
                'lastmod' => '2026-07-17T00:00:00+00:00',
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ],
        ], 'https://example.test');

        $this->assertSame(['https://example.test/private'], array_column($entries, 'loc'));
    }

    public function test_hashed_translations_use_account_site_scope_without_env_site_id(): void
    {
        $storagePath = sys_get_temp_dir() . '/newtxt-laravel-test-' . bin2hex(random_bytes(6));

        config()->set('newtxt.storage_path', $storagePath);
        config()->set('newtxt.public_key', 'site-3-public-key');
        config()->set('newtxt.private_key', 'site-3-private-key');
        config()->set('newtxt.api_key', 'site-3-api-key');

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000003',
                'publicKey' => 'site-3-public-key',
                'sourceLanguage' => 'en',
                'defaultUrlMode' => 'path',
                'navigationMode' => 'redirect',
                'translationMode' => 'seo',
                'cacheTranslatedPages' => true,
                'injectSeoMetadata' => true,
                'seoRobots' => 'index,follow',
                'targetLanguages' => [
                    ['languageCode' => 'fr', 'isDefault' => false],
                ],
            ]),
        ]);

        try {
            $manager = app(NewtxtManager::class);
            $stored = $manager->putHashedTranslation('fr', 'Hello', 'Bonjour');
            $resolved = $manager->hashedTranslation('fr', 'Hello');

            $this->assertIsArray($resolved);
            $this->assertSame($stored['sourceHash'], $resolved['sourceHash']);
            $this->assertSame('00000000-0000-0000-0000-000000000003', $resolved['siteId']);
            $this->assertSame('Bonjour', $resolved['translatedText']);
        } finally {
            (new Filesystem())->deleteDirectory($storagePath);
        }
    }
}
