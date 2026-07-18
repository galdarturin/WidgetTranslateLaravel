<?php

namespace Newtxt\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Newtxt\Laravel\Tests\TestCase;

class PrewarmCommandTest extends TestCase
{
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
