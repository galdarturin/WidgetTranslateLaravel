<?php

namespace Newtxt\Laravel;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;

class NewtxtClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiBaseUrl,
        private readonly string $apiToken,
    ) {
    }

    /**
     * Render a translated page through the authenticated NewTXT API.
     *
     * The API token is applied only on the server-side HTTP request. The
     * returned HTML can be cached locally by NewtxtManager before it is sent to
     * visitors or crawlers.
     */
    public function renderPage(string $siteId, string $languageCode, string $path, array $options = []): array
    {
        $siteId = rawurlencode($siteId);
        $payload = [
            'languageCode' => $languageCode,
            'path' => $path,
            'urlMode' => $options['urlMode'] ?? config('newtxt.url_mode', 'path'),
            'autoTranslateIfMissing' => (bool) ($options['autoTranslateIfMissing'] ?? true),
            'forceRefreshCache' => (bool) ($options['forceRefreshCache'] ?? false),
            'allowPartialTranslations' => (bool) ($options['allowPartialTranslations'] ?? false),
        ];

        return $this->request()
            ->post("/localization/sites/{$siteId}/pages/render", $payload)
            ->throw()
            ->json();
    }

    /**
     * Read page node translations for local hashed translation storage.
     *
     * This endpoint is intentionally separate from renderPage() because
     * translation memory sync can run without requesting rendered HTML.
     */
    public function pageTranslations(string $siteId, string $languageCode, string $path, array $options = []): array
    {
        $siteId = rawurlencode($siteId);
        $query = [
            'path' => $path,
            'languageCode' => $languageCode,
            'urlMode' => $options['urlMode'] ?? config('newtxt.url_mode', 'path'),
            'autoTranslateIfMissing' => (bool) ($options['autoTranslateIfMissing'] ?? false),
            'includeHtml' => (bool) ($options['includeHtml'] ?? false),
            'allowPartialHtml' => (bool) ($options['allowPartialHtml'] ?? false),
        ];

        return $this->request()
            ->get("/localization/sites/{$siteId}/pages/translations", $query)
            ->throw()
            ->json();
    }

    /**
     * Reset NewTXT's remote rendered page cache for a page or language.
     *
     * Local Laravel cache is cleared separately by NewtxtManager because the
     * local cache key includes package-specific scope and URL-mode fields.
     */
    public function resetRemotePageCache(string $siteId, string $path, ?string $languageCode = null, ?string $urlMode = null): array
    {
        $siteId = rawurlencode($siteId);

        return $this->request()
            ->post("/localization/sites/{$siteId}/pages/cache/reset", [
                'path' => $path,
                'languageCode' => $languageCode,
                'urlMode' => $urlMode,
            ])
            ->throw()
            ->json();
    }

    /**
     * Build a preconfigured HTTP request with safe headers.
     */
    private function request(): PendingRequest
    {
        $request = $this->http
            ->baseUrl($this->apiBaseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 250, throw: false);

        if ($this->apiToken !== '') {
            $request->withToken($this->apiToken);
        }

        return $request;
    }
}
