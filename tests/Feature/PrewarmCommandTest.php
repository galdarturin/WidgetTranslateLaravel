<?php

namespace Newtxt\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Newtxt\Laravel\Tests\TestCase;

class PrewarmCommandTest extends TestCase
{
    public function test_prewarm_uses_all_configured_account_target_languages_by_default(): void
    {
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 0);
        config()->set('newtxt.sync_hashed_translations_on_prewarm', false);

        $renderedLanguages = [];

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000061',
                'publicKey' => 'public-site-key',
                'sourceLanguage' => 'en',
                'defaultUrlMode' => 'path',
                'translationMode' => 'seo',
                'cacheTranslatedPages' => true,
                'targetLanguages' => [
                    ['languageCode' => 'en', 'displayName' => 'English', 'isDefault' => true],
                    ['languageCode' => 'ka', 'displayName' => 'Georgian', 'isDefault' => false],
                    ['languageCode' => 'hy', 'displayName' => 'Armenian', 'isDefault' => false],
                    ['languageCode' => 'de', 'displayName' => 'German', 'isDefault' => false],
                ],
            ]),
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => function ($request) use (&$renderedLanguages) {
                $payload = $request->data();
                $languageCode = (string) ($payload['languageCode'] ?? '');
                $renderedLanguages[] = $languageCode;

                return Http::response([
                    'siteId' => '00000000-0000-0000-0000-000000000061',
                    'languageCode' => $languageCode,
                    'path' => '/about',
                    'urlMode' => 'path',
                    'translatedUrl' => "https://example.test/{$languageCode}/about",
                    'html' => "<html lang=\"{$languageCode}\" data-cservice-rendered=\"translated-html\" data-cservice-rendered-language=\"{$languageCode}\"><head><title>Translated {$languageCode}</title></head><body><main><h1>Translated page</h1></main></body></html>",
                ]);
            },
        ]);

        $this->artisan('newtxt:prewarm', [
            '--path' => ['/about'],
        ])
            ->expectsOutput('Prewarmed ka /about')
            ->expectsOutput('Prewarmed hy /about')
            ->expectsOutput('Prewarmed de /about')
            ->expectsOutput('Prewarm completed. Rendered entries: 3. Hashed translations stored: 0.')
            ->assertExitCode(0);

        sort($renderedLanguages);
        $this->assertSame(['de', 'hy', 'ka'], $renderedLanguages);
    }

    public function test_prewarm_counts_only_ready_translated_pages(): void
    {
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 0);
        config()->set('newtxt.sync_hashed_translations_on_prewarm', false);

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/settings' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000060',
                'publicKey' => 'public-site-key',
                'sourceLanguage' => 'en',
                'defaultUrlMode' => 'path',
                'translationMode' => 'seo',
                'cacheTranslatedPages' => true,
                'targetLanguages' => [
                    ['languageCode' => 'fr', 'displayName' => 'French', 'isDefault' => false],
                ],
            ]),
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000060',
                'languageCode' => 'fr',
                'path' => '/about',
                'urlMode' => 'path',
                'translatedUrl' => 'https://example.test/fr/about',
                'html' => '<html><head><title>Pending</title></head><body><main>Pending translated page</main></body></html>',
            ]),
        ]);

        $this->artisan('newtxt:prewarm', [
            '--language' => ['fr'],
            '--path' => ['/about'],
        ])
            ->expectsOutput('Skipped fr /about')
            ->expectsOutput('Prewarm completed. Rendered entries: 0. Hashed translations stored: 0.')
            ->assertExitCode(0);
    }
}
