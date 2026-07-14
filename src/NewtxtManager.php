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
use Throwable;

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

        $siteKey = (string) $this->config->get('newtxt.public_key', '');
        $loaderUrl = (string) $this->config->get('newtxt.widget_loader_url', '');
        if ($siteKey === '' || $loaderUrl === '') {
            return '';
        }

        $attributes = array_merge([
            'src' => $loaderUrl,
            'data-site-key' => $siteKey,
            'data-navigation-mode' => $this->navigationMode(),
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

        if ($this->publicKey() === '') {
            return null;
        }

        try {
            $rendered = $this->client->renderPage(
                $this->normalizeLanguageCode($languageCode),
                $this->normalizePath($path),
                array_merge(['urlMode' => $this->urlMode()], $options),
            );
        } catch (Throwable) {
            return null;
        }

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

        if (!$this->cacheTranslatedPages() || $ttl === 0 || (bool) ($options['forceRefreshCache'] ?? false)) {
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

        $languageCode = $this->normalizeLanguageCode($languageCode);
        $path = $this->normalizePath($path);
        $payload = $this->client->pageTranslations($languageCode, $path, array_merge([
            'urlMode' => $this->urlMode(),
            'includeHtml' => false,
            'autoTranslateIfMissing' => false,
        ], $options));

        $nodes = is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [];

        return $this->translations->putNodes($languageCode, $nodes, [
            'siteId' => $this->siteId(),
            'path' => (string) ($payload['path'] ?? $path),
            'urlMode' => (string) ($payload['urlMode'] ?? ($options['urlMode'] ?? $this->urlMode())),
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
        $siteId = $this->siteId();
        if ($siteId !== '' && !array_key_exists('siteId', $metadata)) {
            $metadata['siteId'] = $siteId;
        }

        return $this->translations->put($languageCode, $sourceText, $translatedText, $metadata);
    }

    /**
     * Resolve one local translation memory entry by source text.
     */
    public function hashedTranslation(string $languageCode, string $sourceText): ?array
    {
        return $this->translations->get($languageCode, $sourceText, $this->siteId());
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

        $sourceLanguage = $this->sourceLanguage();
        $path = $this->normalizePath($path);
        $urlMode = (string) ($options['urlMode'] ?? $this->urlMode());
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
     * Return true when account settings allow server-side translated pages.
     */
    public function canServeTranslatedPages(): bool
    {
        return $this->enabled() && $this->translationMode() === 'seo';
    }

    /**
     * Read and cache account-controlled site settings from the NewTXT API.
     */
    public function accountSettings(bool $forceRefresh = false): array
    {
        $siteKey = $this->publicKey();
        if ($siteKey === '') {
            return [];
        }

        $load = function (): array {
            try {
                $settings = $this->client->siteSettings();

                return is_array($settings) ? $settings : [];
            } catch (Throwable) {
                return [];
            }
        };

        $ttl = max(0, (int) $this->config->get('newtxt.account_settings_cache_ttl', 300));
        if ($forceRefresh || $ttl === 0) {
            return $load();
        }

        $prefix = trim((string) $this->config->get('newtxt.account_settings_cache_prefix', 'newtxt:account-settings'), ':');

        return $this->cacheStore()->remember($prefix . ':' . sha1($siteKey), $ttl, $load);
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

        return $this->canServeTranslatedPages() && in_array($candidate, $this->targetLanguages(), true) ? $candidate : null;
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

        $siteId = trim((string) ($rendered['siteId'] ?? $this->siteId()));
        if ($siteId === '') {
            return $rendered;
        }

        $languageCode = $this->normalizeLanguageCode($languageCode);
        $path = $this->normalizePath($path);
        $urlMode = (string) ($options['urlMode'] ?? $rendered['urlMode'] ?? $this->urlMode());
        $html = $rendered['html'];

        if ($this->injectSeoMetadata()) {
            $html = $this->seo->apply($html, $this->seoMetadataForRenderedPage($rendered, $options));
            $rendered['html'] = $html;
        }

        $version = (string) $this->config->get('newtxt.page_hash_version', 'newtxt-laravel-v1');
        $rendered['htmlHash'] = $this->hasher->htmlHash($html);
        $rendered['pageHash'] = $this->hasher->pageHash($siteId, $languageCode, $urlMode, $path, $html, $version);
        $rendered['pageHashVersion'] = $version;
        $rendered['storedAt'] = gmdate('c');

        if ($this->cacheTranslatedPages() && (bool) $this->config->get('newtxt.store_rendered_pages', true)) {
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
            'robots' => $this->seoRobots(),
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
        $urlMode = (string) ($options['urlMode'] ?? $this->urlMode());
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
        $configured = trim((string) $this->config->get('newtxt.site_id', ''));
        if ($configured !== '') {
            return $configured;
        }

        return trim((string) Arr::get($this->accountSettings(), 'siteId', ''));
    }

    /**
     * Return the public dashboard key used for widget and public SEO endpoints.
     */
    private function publicKey(): string
    {
        return trim((string) $this->config->get('newtxt.public_key', ''));
    }

    /**
     * Return account source language with a local fallback.
     */
    private function sourceLanguage(): string
    {
        return $this->normalizeLanguageCode((string) $this->firstAccountValue([
            'sourceLanguageCode',
            'sourceLanguage',
            'site.sourceLanguage',
            'settings.sourceLanguage',
        ], $this->config->get('newtxt.source_language', 'en')));
    }

    /**
     * Return account URL mode with a local fallback.
     */
    private function urlMode(): string
    {
        $value = strtolower(trim((string) $this->firstAccountValue([
            'defaultUrlMode',
            'urlMode',
            'widgetSettings.defaultUrlMode',
            'settings.defaultUrlMode',
            'rendering.urlMode',
        ], $this->config->get('newtxt.url_mode', 'path'))));

        return in_array($value, ['path', 'subdomain'], true) ? $value : 'path';
    }

    /**
     * Return account widget navigation mode with a local fallback.
     */
    private function navigationMode(): string
    {
        $value = strtolower(trim((string) $this->firstAccountValue([
            'navigationMode',
            'widgetSettings.navigationMode',
            'settings.navigationMode',
            'widget.navigationMode',
        ], $this->config->get('newtxt.navigation_mode', 'redirect'))));

        return in_array($value, ['redirect', 'replace'], true) ? $value : 'redirect';
    }

    /**
     * Return account rendering mode with a local fallback.
     */
    private function translationMode(): string
    {
        $value = strtolower(trim((string) $this->firstAccountValue([
            'translationMode',
            'widgetSettings.translationMode',
            'settings.translationMode',
        ], 'seo')));

        return in_array($value, ['seo', 'client'], true) ? $value : 'seo';
    }

    /**
     * Return whether rendered translated pages should use local cache/artifacts.
     */
    private function cacheTranslatedPages(): bool
    {
        return $this->boolAccountValue(['cacheTranslatedPages', 'widgetSettings.cacheTranslatedPages', 'settings.cacheTranslatedPages'], true);
    }

    /**
     * Return whether local SEO metadata injection is enabled.
     */
    private function injectSeoMetadata(): bool
    {
        return $this->boolAccountValue(['injectSeoMetadata', 'settings.injectSeoMetadata'], (bool) $this->config->get('newtxt.inject_seo_metadata', true));
    }

    /**
     * Return account robots metadata with a local fallback.
     */
    private function seoRobots(): string
    {
        $value = trim((string) $this->firstAccountValue([
            'seoRobots',
            'settings.seoRobots',
            'rendering.seoRobots',
        ], $this->config->get('newtxt.seo_robots', 'index,follow')));

        return $value !== '' ? $value : 'index,follow';
    }

    /**
     * Return configured target languages as normalized codes.
     */
    public function targetLanguages(): array
    {
        $languages = $this->normalizeLanguageList($this->firstAccountValue([
            'targetLanguages',
            'site.targetLanguages',
            'languages',
            'settings.targetLanguages',
        ], []), true);

        if ($languages !== []) {
            return $languages;
        }

        return $this->normalizeLanguageList($this->config->get('newtxt.target_languages', []));
    }

    /**
     * Return the first non-empty value from account settings.
     */
    private function firstAccountValue(array $keys, mixed $default = null): mixed
    {
        $settings = $this->accountSettings();
        foreach ($keys as $key) {
            $value = Arr::get($settings, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Return a boolean account setting with strict fallback behavior.
     */
    private function boolAccountValue(array $keys, bool $default): bool
    {
        $value = $this->firstAccountValue($keys, null);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Normalize string, CSV, or API object language lists.
     */
    private function normalizeLanguageList(mixed $languages, bool $excludeDefault = false): array
    {
        if (is_string($languages)) {
            $languages = explode(',', $languages);
        }

        return collect(Arr::wrap($languages))
            ->map(function ($language) use ($excludeDefault) {
                if (is_array($language)) {
                    if ($excludeDefault && filter_var($language['isDefault'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === true) {
                        return null;
                    }

                    $isActive = $language['isActive'] ?? $language['active'] ?? true;
                    if (filter_var($isActive, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false) {
                        return null;
                    }

                    return $language['languageCode'] ?? $language['code'] ?? null;
                }

                return $language;
            })
            ->map(fn ($language) => strtolower(trim((string) $language)))
            ->filter(fn ($language) => preg_match('/^[a-z0-9_-]{2,20}$/', $language) === 1)
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
