<?php

namespace Newtxt\Laravel;

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Newtxt\Laravel\Html\PageHasher;
use Newtxt\Laravel\Html\SeoMetadataInjector;
use Newtxt\Laravel\Storage\HashedTranslationStore;
use Newtxt\Laravel\Storage\RenderedPageSnapshotStore;

class NewtxtManager
{
    public function __construct(
        private readonly NewtxtClient $client,
        private readonly CacheManager $cache,
        private readonly ConfigRepository $config,
        private readonly HashedTranslationStore $translations,
        private readonly RenderedPageSnapshotStore $snapshots,
        private readonly PageHasher $hasher,
        private readonly SeoMetadataInjector $seo,
    ) {
    }

    /**
     * Render the language switcher widget controlled by the Laravel package.
     *
     * This keeps the "one installation per site" rule intact: Laravel
     * customers call @newtxtWidget() instead of pasting the standalone script.
     */
    public function widgetSnippet(array $attributes = []): string
    {
        if (!$this->enabled()) {
            return '';
        }

        $siteKey = (string) $this->config->get('newtxt.widget_key', $this->config->get('newtxt.site_key', ''));
        $loaderUrl = (string) $this->config->get('newtxt.widget_loader_url', '');
        if ($siteKey === '' || $loaderUrl === '') {
            return '';
        }

        $attributes = array_merge([
            'src' => $loaderUrl,
            'data-site-key' => $siteKey,
            'data-navigation-mode' => (string) $this->config->get('newtxt.navigation_mode', 'redirect'),
        ], $attributes);

        return '<script ' . $this->htmlAttributes($attributes) . '></script>';
    }

    /**
     * Render translated HTML without touching local cache.
     *
     * Application code can use this when it needs fresh translated HTML, while
     * middleware should normally use rememberRenderedPage().
     */
    public function renderPage(string $languageCode, string $path, array $options = []): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $siteId = (string) $this->config->get('newtxt.site_id', '');
        if ($siteId === '') {
            return null;
        }

        $rendered = $this->client->renderPage(
            $siteId,
            $this->normalizeLanguageCode($languageCode),
            $this->normalizePath($path),
            $options,
        );

        return $this->prepareRenderedPage($rendered, $languageCode, $path, $options);
    }

    /**
     * Render translated HTML and store it in Laravel cache.
     *
     * The cache key includes language, source path, query string, URL mode, and
     * site identity so different public pages never share translated HTML.
     */
    public function rememberRenderedPage(string $languageCode, string $path, array $options = []): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $languageCode = $this->normalizeLanguageCode($languageCode);
        $path = $this->normalizePath($path);
        $cacheKey = $this->cacheKey($languageCode, $path, $options);
        $ttl = max(0, (int) $this->config->get('newtxt.cache_ttl', 86400));

        if ($ttl === 0 || (bool) ($options['forceRefreshCache'] ?? false)) {
            return $this->renderPage($languageCode, $path, $options);
        }

        return $this->cacheStore()->remember($cacheKey, $ttl, function () use ($languageCode, $path, $options) {
            return $this->renderPage($languageCode, $path, $options);
        });
    }

    /**
     * Clear local translated HTML cache.
     *
     * Laravel cache stores do not support safe prefix deletes consistently. The
     * package refuses broad flushes to avoid deleting unrelated application
     * cache entries from a shared store.
     */
    public function clearRenderedPageCache(?string $languageCode = null, ?string $path = null): void
    {
        if ($languageCode !== null && $path !== null) {
            $this->cacheStore()->forget($this->cacheKey($languageCode, $path));
            return;
        }

        throw new InvalidArgumentException('languageCode and path are required for safe local cache clearing.');
    }

    /**
     * Store API-provided page translations as local hash-addressed artifacts.
     *
     * Developers can run this from deploy jobs or queues to keep reusable
     * fragment translations close to the Laravel application.
     */
    public function syncHashedTranslations(string $languageCode, string $path, array $options = []): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        $siteId = $this->siteId();
        if ($siteId === '') {
            return 0;
        }

        $languageCode = $this->normalizeLanguageCode($languageCode);
        $path = $this->normalizePath($path);
        $payload = $this->client->pageTranslations($siteId, $languageCode, $path, array_merge([
            'includeHtml' => false,
            'autoTranslateIfMissing' => false,
        ], $options));

        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];

        return $this->translations->putNodes($languageCode, $nodes, [
            'path' => (string) ($payload['path'] ?? $path),
            'urlMode' => (string) ($payload['urlMode'] ?? ($options['urlMode'] ?? $this->config->get('newtxt.url_mode', 'path'))),
            'translatedUrl' => $payload['translatedUrl'] ?? null,
            'linkTags' => $payload['linkTags'] ?? [],
            'syncedAt' => gmdate('c'),
        ]);
    }

    /**
     * Store one local translation memory entry by source text hash.
     */
    public function putHashedTranslation(string $languageCode, string $sourceText, string $translatedText, array $metadata = []): array
    {
        return $this->translations->put($languageCode, $sourceText, $translatedText, $metadata);
    }

    /**
     * Resolve one local translation memory entry by source text.
     */
    public function hashedTranslation(string $languageCode, string $sourceText): ?array
    {
        return $this->translations->get($languageCode, $sourceText);
    }

    /**
     * Record a source page snapshot hash after Laravel renders public HTML.
     *
     * The full source HTML is optional because many applications only need a
     * deterministic source hash for invalidation and debugging.
     */
    public function recordSourcePage(string $path, string $html, array $options = []): ?array
    {
        if (!$this->enabled() || !(bool) $this->config->get('newtxt.store_source_page_hashes', true)) {
            return null;
        }

        $siteId = $this->siteId();
        if ($siteId === '' || trim($html) === '') {
            return null;
        }

        $sourceLanguage = $this->normalizeLanguageCode((string) $this->config->get('newtxt.source_language', 'en'));
        $path = $this->normalizePath($path);
        $urlMode = (string) ($options['urlMode'] ?? $this->config->get('newtxt.url_mode', 'path'));
        $version = (string) $this->config->get('newtxt.page_hash_version', 'newtxt-laravel-v1');
        $snapshot = [
            'siteId' => $siteId,
            'languageCode' => $sourceLanguage,
            'path' => $path,
            'urlMode' => $urlMode,
            'htmlHash' => $this->hasher->htmlHash($html),
            'pageHash' => $this->hasher->pageHash($siteId, $sourceLanguage, $urlMode, $path, $html, $version),
            'pageHashVersion' => $version,
            'source' => 'laravel-response',
            'query' => (string) ($options['query'] ?? ''),
            'html' => $html,
        ];

        $this->snapshots->put($snapshot, (bool) $this->config->get('newtxt.store_source_html', false));

        unset($snapshot['html']);

        return $snapshot;
    }

    /**
     * Return true when the integration can perform server-side work.
     */
    public function enabled(): bool
    {
        return (bool) $this->config->get('newtxt.enabled', true);
    }

    /**
     * Determine whether the first path segment is a configured target language.
     */
    public function extractLanguageFromPath(string $path): ?string
    {
        $segments = explode('/', trim($path, '/'));
        $candidate = strtolower($segments[0] ?? '');
        if ($candidate === '') {
            return null;
        }

        return in_array($candidate, $this->targetLanguages(), true) ? $candidate : null;
    }

    /**
     * Remove the language prefix before asking NewTXT to render source content.
     */
    public function sourcePathForTranslatedPath(string $path, string $languageCode): string
    {
        $languageCode = preg_quote($this->normalizeLanguageCode($languageCode), '#');
        $sourcePath = preg_replace("#^/{$languageCode}(/|$)#", '/', $this->normalizePath($path), 1);

        return $this->normalizePath($sourcePath ?: '/');
    }

    /**
     * Build the cache store selected by configuration.
     */
    private function cacheStore()
    {
        $store = $this->config->get('newtxt.cache_store');

        return $store ? $this->cache->store($store) : $this->cache->store();
    }

    /**
     * Add local SEO metadata, page hashes, and optional project artifacts.
     */
    private function prepareRenderedPage(array $rendered, string $languageCode, string $path, array $options): array
    {
        if (!isset($rendered['html']) || !is_string($rendered['html']) || trim($rendered['html']) === '') {
            return $rendered;
        }

        $siteId = $this->siteId();
        if ($siteId === '') {
            return $rendered;
        }

        $languageCode = $this->normalizeLanguageCode($languageCode);
        $path = $this->normalizePath($path);
        $urlMode = (string) ($options['urlMode'] ?? $rendered['urlMode'] ?? $this->config->get('newtxt.url_mode', 'path'));
        $html = $rendered['html'];

        if ((bool) $this->config->get('newtxt.inject_seo_metadata', true)) {
            $html = $this->seo->apply($html, $this->seoMetadataForRenderedPage($rendered, $options));
            $rendered['html'] = $html;
        }

        $version = (string) $this->config->get('newtxt.page_hash_version', 'newtxt-laravel-v1');
        $rendered['htmlHash'] = $this->hasher->htmlHash($html);
        $rendered['pageHash'] = $this->hasher->pageHash($siteId, $languageCode, $urlMode, $path, $html, $version);
        $rendered['pageHashVersion'] = $version;
        $rendered['storedAt'] = gmdate('c');

        if ((bool) $this->config->get('newtxt.store_rendered_pages', true)) {
            $this->snapshots->put(array_merge($rendered, [
                'siteId' => $siteId,
                'languageCode' => $languageCode,
                'path' => $path,
                'urlMode' => $urlMode,
                'query' => (string) ($options['query'] ?? ''),
            ]), (bool) $this->config->get('newtxt.store_rendered_html', true));
        }

        return $rendered;
    }

    /**
     * Build SEO metadata for a translated HTML document.
     */
    private function seoMetadataForRenderedPage(array $rendered, array $options): array
    {
        $metadata = [
            'canonicalUrl' => $rendered['translatedUrl'] ?? null,
            'robots' => $this->config->get('newtxt.seo_robots', 'index,follow'),
        ];

        if (isset($rendered['linkTags']) && is_array($rendered['linkTags'])) {
            $metadata['alternates'] = collect($rendered['linkTags'])
                ->filter(fn ($tag) => is_array($tag))
                ->map(fn ($tag) => [
                    'href' => $tag['href'] ?? null,
                    'hreflang' => $tag['hrefLang'] ?? $tag['hreflang'] ?? null,
                ])
                ->values()
                ->all();
        }

        $customMetadata = is_array($options['seo'] ?? null) ? $options['seo'] : [];

        return array_merge($metadata, $customMetadata);
    }

    /**
     * Create a deterministic translated HTML cache key.
     */
    private function cacheKey(string $languageCode, string $path, array $options = []): string
    {
        $siteId = $this->siteId() ?: 'unknown-site';
        $urlMode = (string) ($options['urlMode'] ?? $this->config->get('newtxt.url_mode', 'path'));
        $query = (string) ($options['query'] ?? '');
        $hash = sha1(json_encode([$siteId, $languageCode, $urlMode, $path, $query], JSON_THROW_ON_ERROR));

        return trim((string) $this->config->get('newtxt.cache_prefix', 'newtxt:rendered-pages'), ':') . ':' . $hash;
    }

    /**
     * Normalize paths before cache and API use.
     */
    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '//' ? '/' : $path;
    }

    /**
     * Keep language codes lowercase and URL-safe.
     */
    private function normalizeLanguageCode(string $languageCode): string
    {
        return strtolower(trim($languageCode));
    }

    /**
     * Return the configured site ID for API, cache, and artifact scope.
     */
    private function siteId(): string
    {
        return trim((string) $this->config->get('newtxt.site_id', ''));
    }

    /**
     * Return configured target languages as normalized codes.
     */
    private function targetLanguages(): array
    {
        return collect(Arr::wrap($this->config->get('newtxt.target_languages', [])))
            ->map(fn ($language) => strtolower(trim((string) $language)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Render safe HTML attributes for the script tag.
     */
    private function htmlAttributes(array $attributes): string
    {
        return collect($attributes)
            ->filter(fn ($value) => $value !== null && $value !== false && $value !== '')
            ->map(function ($value, string $key) {
                if ($value === true) {
                    return e($key);
                }

                return e(Str::kebab($key)) . '="' . e((string) $value) . '"';
            })
            ->implode(' ');
    }
}
