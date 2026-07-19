<?php

namespace Newtxt\Laravel;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Newtxt\Laravel\Support\ConfigCredential;

class NewtxtClient
{
    private const ApiBaseUrl = 'https://api-v1.newtxt.io/api/v1';

    private readonly HttpFactory $http;

    private readonly string $apiKey;

    private readonly string $publicKey;

    private readonly string $privateKey;

    public function __construct(
        HttpFactory $http,
        string $apiKey,
        string $publicKey,
        string $privateKey,
    ) {
        $this->http = $http;
        $this->apiKey = ConfigCredential::value($apiKey);
        $this->publicKey = ConfigCredential::value($publicKey);
        $this->privateKey = ConfigCredential::value($privateKey);
    }

    /**
     * Read customer-controlled widget and rendering settings for this site.
     */
    public function siteSettings(): array
    {
        return $this->get('/localization/integrations/laravel/settings');
    }

    /**
     * Send a signed GET request and return an array payload.
     */
    private function get(string $path, array $query = []): array
    {
        $payload = $this->request($this->signedHeaders('GET', $this->canonicalTarget($path, $query), ''))
            ->get($this->requestPath($path), $query)
            ->throw()
            ->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * Render a translated page through the authenticated NewTXT API.
     *
     * Dashboard-issued keys are applied only on the server-side HTTP request. The
     * returned HTML can be cached locally by NewtxtManager before it is sent to
     * visitors or crawlers.
     */
    public function renderPage(string $languageCode, string $path, array $options = []): array
    {
        $payload = [
            'languageCode' => $languageCode,
            'path' => $path,
            'urlMode' => $options['urlMode'] ?? 'path',
            'autoTranslateIfMissing' => (bool) ($options['autoTranslateIfMissing'] ?? true),
            'forceRefreshCache' => (bool) ($options['forceRefreshCache'] ?? false),
            'allowPartialTranslations' => (bool) ($options['allowPartialTranslations'] ?? false),
        ];

        $query = ltrim(trim((string) ($options['query'] ?? '')), '?');
        if ($query !== '') {
            $payload['query'] = $query;
        }

        $sourceSeoMetadata = $this->sourceSeoMetadata($options['sourceSeoMetadata'] ?? []);
        if ($sourceSeoMetadata !== []) {
            $payload['sourceSeoMetadata'] = $sourceSeoMetadata;
        }

        return $this->post('/localization/integrations/laravel/pages/render', $payload);
    }

    /**
     * Read page node translations for local hashed translation storage.
     *
     * This endpoint is intentionally separate from renderPage() because
     * translation memory sync can run without requesting rendered HTML.
     */
    public function pageTranslations(string $languageCode, string $path, array $options = []): array
    {
        $query = [
            'path' => $path,
            'languageCode' => $languageCode,
            'urlMode' => $options['urlMode'] ?? 'path',
            'autoTranslateIfMissing' => (bool) ($options['autoTranslateIfMissing'] ?? false),
            'includeHtml' => (bool) ($options['includeHtml'] ?? false),
            'allowPartialHtml' => (bool) ($options['allowPartialHtml'] ?? false),
        ];

        $renderQuery = ltrim(trim((string) ($options['query'] ?? '')), '?');
        if ($renderQuery !== '') {
            $query['query'] = $renderQuery;
        }

        return $this->get('/localization/integrations/laravel/pages/translations', $query);
    }

    /**
     * Reset NewTXT's remote rendered page cache for a page or language.
     *
     * Local Laravel cache is cleared separately by NewtxtManager because the
     * local cache key includes package-specific scope and URL-mode fields.
     */
    public function resetRemotePageCache(string $path, ?string $languageCode = null, ?string $urlMode = null): array
    {
        return $this->post('/localization/integrations/laravel/pages/cache/reset', [
            'path' => $path,
            'languageCode' => $languageCode,
            'urlMode' => $urlMode,
        ]);
    }

    /**
     * Send a signed POST request and return an array payload.
     */
    private function post(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = $this->request($this->signedHeaders('POST', $this->canonicalTarget($path, []), $body))
            ->withBody($body, 'application/json')
            ->send('POST', $this->requestPath($path))
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /**
     * Keep source SEO render context bounded to known text metadata fields.
     */
    private function sourceSeoMetadata(mixed $metadata): array
    {
        if (!is_array($metadata)) {
            return [];
        }

        $normalized = [];
        foreach ([
            'title' => 512,
            'description' => 1024,
            'keywords' => 1024,
            'openGraphTitle' => 512,
            'openGraphDescription' => 1024,
            'twitterTitle' => 512,
            'twitterDescription' => 1024,
        ] as $key => $limit) {
            $value = $this->textMetadataValue($metadata[$key] ?? null, $limit);
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Normalize safe plain-text metadata values.
     */
    private function textMetadataValue(mixed $value, int $limit): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        $text = trim(strip_tags((string) $value));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit);
        }

        return substr($text, 0, $limit);
    }

    /**
     * Build a preconfigured HTTP request with safe headers.
     */
    private function request(array $headers = []): PendingRequest
    {
        $request = $this->http
            ->baseUrl(self::ApiBaseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 250, throw: false);

        $headers = array_filter([
            'X-NewTXT-Api-Key' => $this->apiKey,
            'X-NewTXT-Public-Key' => $this->publicKey,
        ] + $headers, fn ($value) => trim((string) $value) !== '');

        if ($headers !== []) {
            $request->withHeaders($headers);
        }

        return $request;
    }

    /**
     * Sign requests without transmitting the private key.
     */
    private function signedHeaders(string $method, string $target, string $body): array
    {
        if (trim($this->privateKey) === '') {
            return [];
        }

        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.' . strtoupper($method) . '.' . $target . '.' . $body, $this->privateKey);

        return [
            'X-NewTXT-Timestamp' => $timestamp,
            'X-NewTXT-Signature' => 'sha256=' . $signature,
        ];
    }

    /**
     * Build the request target used by the server-side signature verifier.
     */
    private function canonicalTarget(string $path, array $query): string
    {
        $path = '/' . ltrim($path, '/');
        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Keep relative request paths from replacing the configured base URL path.
     */
    private function requestPath(string $path): string
    {
        return ltrim($path, '/');
    }
}
