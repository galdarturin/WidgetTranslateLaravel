<?php

namespace Newtxt\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Newtxt\Laravel\Tests\TestCase;

class SeoMiddlewareIntegrationTest extends TestCase
{
    public function test_env_config_widget_directive_and_seo_middleware_work_together(): void
    {
        config()->set('newtxt.public_key', 'public-site-key');
        config()->set('newtxt.private_key', 'private-site-key');
        config()->set('newtxt.api_key', 'api-site-key');
        config()->set('newtxt.account_settings_cache_ttl', 0);
        config()->set('newtxt.cache_ttl', 0);

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
                'html' => '<html><head><title>Bonjour</title></head><body><main>Bonjour</main></body></html>',
            ]),
        ]);

        Route::middleware(['web', 'newtxt.render'])->get('/{path?}', function () {
            return response('<html><head><title>Source</title></head><body><main>Source</main></body></html>', 200)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        })->where('path', '.*');

        $widget = Blade::render('@newtxtWidget()');
        $this->assertStringContainsString('src="https://cdn.newtxt.io/widget/v1/loader.js"', $widget);
        $this->assertStringContainsString('data-site-key="public-site-key"', $widget);
        $this->assertStringNotContainsString('private-site-key', $widget);
        $this->assertStringNotContainsString('api-site-key', $widget);

        $response = $this->get('/fr/about?utm=campaign');

        $response->assertOk();
        $response->assertSee('Bonjour', false);
        $response->assertDontSee('Source', false);
        $response->assertSee('<link rel="canonical" href="https://example.test/fr/about">', false);
        $response->assertSee('<meta name="robots" content="index,follow">', false);

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
}
