<?php

namespace Newtxt\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Newtxt\Laravel\NewtxtClient;
use Newtxt\Laravel\Tests\TestCase;

class ClientSignatureTest extends TestCase
{
    public function test_client_signs_the_exact_post_body_sent_to_laravel_integration_api(): void
    {
        config()->set('newtxt.public_key', 'public-key');
        config()->set('newtxt.private_key', 'private-key');
        config()->set('newtxt.api_key', 'api-key');

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/render' => Http::response([
                'siteId' => '00000000-0000-0000-0000-000000000001',
                'languageCode' => 'fr',
                'path' => '/about',
                'urlMode' => 'path',
                'originalUrl' => 'https://example.test/about',
                'translatedUrl' => 'https://example.test/fr/about',
                'fromCache' => false,
                'cacheEnabled' => true,
                'cachedAtUtc' => '2026-07-14T00:00:00Z',
                'html' => '<html><body>Bonjour</body></html>',
            ]),
        ]);

        app(NewtxtClient::class)->renderPage('fr', '/about', ['urlMode' => 'path']);

        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-NewTXT-Timestamp')[0] ?? '';
            $expectedSignature = 'sha256=' . hash_hmac(
                'sha256',
                $timestamp . '.POST./localization/integrations/laravel/pages/render.' . $request->body(),
                'private-key',
            );

            return $request->method() === 'POST'
                && str_contains((string) $request->url(), '/integrations/laravel/pages/render')
                && str_contains($request->body(), '"path":"/about"')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->hasHeader('X-NewTXT-Api-Key', 'api-key')
                && $request->hasHeader('X-NewTXT-Public-Key', 'public-key')
                && $request->hasHeader('X-NewTXT-Signature', $expectedSignature)
                && !$request->hasHeader('X-NewTXT-Private-Key');
        });
    }

    public function test_client_signs_page_translation_sync_query_requests(): void
    {
        config()->set('newtxt.public_key', 'public-key');
        config()->set('newtxt.private_key', 'private-key');
        config()->set('newtxt.api_key', 'api-key');

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/translations*' => Http::response([
                'nodes' => [],
            ]),
        ]);

        app(NewtxtClient::class)->pageTranslations('fr', '/about', [
            'urlMode' => 'path',
            'autoTranslateIfMissing' => true,
            'includeHtml' => true,
            'allowPartialHtml' => true,
        ]);

        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-NewTXT-Timestamp')[0] ?? '';
            $target = '/localization/integrations/laravel/pages/translations'
                . '?path=%2Fabout&languageCode=fr&urlMode=path'
                . '&autoTranslateIfMissing=1&includeHtml=1&allowPartialHtml=1';
            $expectedSignature = 'sha256=' . hash_hmac(
                'sha256',
                $timestamp . '.GET.' . $target . '.',
                'private-key',
            );

            return $request->method() === 'GET'
                && str_contains((string) $request->url(), $target)
                && $request->hasHeader('X-NewTXT-Api-Key', 'api-key')
                && $request->hasHeader('X-NewTXT-Public-Key', 'public-key')
                && $request->hasHeader('X-NewTXT-Signature', $expectedSignature)
                && !$request->hasHeader('X-NewTXT-Private-Key');
        });
    }

    public function test_client_signs_remote_page_cache_reset_requests(): void
    {
        config()->set('newtxt.public_key', 'public-key');
        config()->set('newtxt.private_key', 'private-key');
        config()->set('newtxt.api_key', 'api-key');

        Http::fake([
            'https://api-v1.newtxt.io/api/v1/localization/integrations/laravel/pages/cache/reset' => Http::response([
                'ok' => true,
            ]),
        ]);

        app(NewtxtClient::class)->resetRemotePageCache('/about', 'fr', 'path');

        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-NewTXT-Timestamp')[0] ?? '';
            $expectedSignature = 'sha256=' . hash_hmac(
                'sha256',
                $timestamp . '.POST./localization/integrations/laravel/pages/cache/reset.' . $request->body(),
                'private-key',
            );

            return $request->method() === 'POST'
                && str_contains((string) $request->url(), '/integrations/laravel/pages/cache/reset')
                && str_contains($request->body(), '"path":"/about"')
                && str_contains($request->body(), '"languageCode":"fr"')
                && str_contains($request->body(), '"urlMode":"path"')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->hasHeader('X-NewTXT-Api-Key', 'api-key')
                && $request->hasHeader('X-NewTXT-Public-Key', 'public-key')
                && $request->hasHeader('X-NewTXT-Signature', $expectedSignature)
                && !$request->hasHeader('X-NewTXT-Private-Key');
        });
    }
}
