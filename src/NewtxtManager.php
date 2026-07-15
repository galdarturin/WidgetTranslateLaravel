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
     * The cache key includes language, source path, query string, URL mode,
     * site identity, and page hash version so different public pages never
     * share translated HTML.
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
            return $this->localRenderedPageSnapshot($languageCode, $path, $options)
                ?? $this->renderPage($languageCode, $path, $options);
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
            $languageCode = $this->normalizeLanguageCode($languageCode);
            $path = $this->normalizePath($path);
            $this->cacheStore()->forget($this->cacheKey($languageCode, $path));
            $this->snapshots->forget(
                $this->siteId() ?: 'unknown-site',
                $languageCode,
                $this->urlMode(),
                $path,
                '',
                $this->pageHashVersion(),
            );

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
        $version = (string) $this->config->get('newtxt.page_hash_version', 'newtxt-laravel-v2');
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
     * Apply canonical and hreflang metadata to a public source-language page.
     */
    public function applySourcePageSeo(string $path, string $html, array $options = []): string
    {
        if (!$this->canServeTranslatedPages() || !$this->injectSeoMetadata() || trim($html) === '') {
            return $html;
        }

        return $this->seo->apply($html, $this->sourcePageSeoMetadata($path, $options));
    }

    /**
     * Build a complete sitemap list with NewTXT translated entries.
     *
     * The host application owns source URL discovery because it knows its
     * routes and database records. NewTXT owns language selection, translated
     * URL construction, and stored translated page snapshot inclusion.
     *
     * @param  list<array{loc:string,lastmod:string,changefreq:string,priority:string}>  $sourceEntries
     * @param  array{siteId?:string,languages?:mixed,urlMode?:string,includeQueryStrings?:bool}  $options
     * @return list<array{loc:string,lastmod:string,changefreq:string,priority:string}>
     */
    public function sitemapEntries(array $sourceEntries, ?string $siteUrl = null, array $options = []): array
    {
        $entries = array_values($sourceEntries);
        if (!$this->canServeTranslatedPages()) {
            return $entries;
        }

        $languages = $this->sitemapLanguages($options['languages'] ?? null);
        if ($languages === []) {
            return $entries;
        }

        $baseSiteUrl = $this->normalizeSitemapSiteUrl($siteUrl ?? $this->config->get('app.url'));
        if ($baseSiteUrl === null) {
            return $entries;
        }

        $urlMode = $this->normalizeUrlMode($options['urlMode'] ?? $this->urlMode()) ?? 'path';
        $seen = [];

        foreach ($entries as $entry) {
            if (isset($entry['loc']) && is_string($entry['loc'])) {
                $seen[$entry['loc']] = true;
            }
        }

        foreach ($sourceEntries as $entry) {
            $path = $this->sitemapEntryPath($entry);
            if ($path === null || !$this->isSitemapPathAllowed($path)) {
                continue;
            }

            foreach ($languages as $languageCode) {
                $loc = $this->localizedSitemapUrl($baseSiteUrl, $path, $languageCode, $urlMode);
                if (isset($seen[$loc])) {
                    continue;
                }

                $seen[$loc] = true;
                $entries[] = [
                    ...$entry,
                    'loc' => $loc,
                ];
            }
        }

        foreach ($this->renderedPageSitemapEntries($baseSiteUrl, array_merge($options, [
            'languages' => $languages,
            'urlMode' => $urlMode,
        ])) as $entry) {
            $loc = (string) ($entry['loc'] ?? '');
            if ($loc === '' || isset($seen[$loc])) {
                continue;
            }

            $seen[$loc] = true;
            $entries[] = [
                'loc' => $loc,
                'lastmod' => (string) ($entry['lastmod'] ?? $this->sitemapLastModified(null)),
                'changefreq' => (string) ($entry['changefreq'] ?? 'weekly'),
                'priority' => (string) ($entry['priority'] ?? '0.6'),
            ];
        }

        return $entries;
    }

    /**
     * Return sitemap entries for locally stored translated page snapshots.
     *
     * @param  array{siteId?:string,languages?:mixed,urlMode?:string,includeQueryStrings?:bool}  $options
     * @return list<array{loc:string,lastmod:string,languageCode:string,path:string,urlMode:string,pageHash:string,htmlHash:?string}>
     */
    public function renderedPageSitemapEntries(?string $siteUrl = null, array $options = []): array
    {
        if (!$this->canServeTranslatedPages()) {
            return [];
        }

        $languages = $this->sitemapLanguages($options['languages'] ?? null);
        if ($languages === []) {
            return [];
        }

        $siteId = trim((string) ($options['siteId'] ?? $this->siteId()));
        $outputUrlMode = $this->normalizeUrlMode($options['urlMode'] ?? null);
        $includeQueryStrings = filter_var($options['includeQueryStrings'] ?? false, FILTER_VALIDATE_BOOL);
        $baseSiteUrl = $this->normalizeSitemapSiteUrl($siteUrl ?? $this->config->get('app.url'));
        if ($baseSiteUrl === null) {
            return [];
        }

        $entries = [];
        foreach ($this->snapshots->allForSitemap($siteId !== '' ? $siteId : null, $languages) as $snapshot) {
            $languageCode = $this->normalizeLanguageCode((string) ($snapshot['languageCode'] ?? ''));
            if (!in_array($languageCode, $languages, true)) {
                continue;
            }

            $urlMode = $outputUrlMode ?? $this->normalizeUrlMode($snapshot['urlMode'] ?? null);
            if ($urlMode === null) {
                continue;
            }

            $query = ltrim(trim((string) ($snapshot['query'] ?? '')), '?');
            if ($query !== '' && (!$includeQueryStrings || $this->hasUnsafeUrlPart($query))) {
                continue;
            }

            $path = $this->normalizePath((string) ($snapshot['path'] ?? '/'));
            if (!$this->isSitemapPathAllowed($path)) {
                continue;
            }

            $loc = $this->localizedSitemapUrl($baseSiteUrl, $path, $languageCode, $urlMode, $query);

            $entries[] = [
                'loc' => $loc,
                'lastmod' => $this->sitemapLastModified($snapshot['updatedAt'] ?? $snapshot['storedAt'] ?? null),
                'languageCode' => $languageCode,
                'path' => $path,
                'urlMode' => $urlMode,
                'pageHash' => (string) ($snapshot['pageHash'] ?? ''),
                'htmlHash' => isset($snapshot['htmlHash']) ? (string) $snapshot['htmlHash'] : null,
            ];
        }

        return $entries;
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
        if ($siteKey === '' || $this->apiKey() === '' || $this->privateKey() === '') {
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
            $html = $this->seo->apply($html, $this->seoMetadataForRenderedPage($rendered, $languageCode, $path, $options));
            $rendered['html'] = $html;
        }

        $version = $this->pageHashVersion();
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
    private function seoMetadataForRenderedPage(array $rendered, string $languageCode, string $path, array $options): array
    {
        $urlMode = $this->normalizeUrlMode($options['urlMode'] ?? $rendered['urlMode'] ?? $this->urlMode()) ?? 'path';
        $canonicalUrl = $this->safeHttpUrl($rendered['translatedUrl'] ?? null)
            ?? $this->localizedPageUrl($path, $languageCode, $urlMode, $options);

        $metadata = [
            'canonicalUrl' => $canonicalUrl,
            'robots' => $this->seoRobots(),
            'alternates' => $this->pageAlternates($path, $languageCode, $urlMode, $options, $rendered),
        ];

        $title = $this->firstMetadataValue($rendered, [
            'title',
            'pageTitle',
            'seoTitle',
            'metaTitle',
            'metadata.title',
            'metadata.seoTitle',
            'seo.title',
            'seo.metaTitle',
        ]);
        if ($title !== null) {
            $metadata['title'] = $title;
        }

        $description = $this->firstMetadataValue($rendered, [
            'description',
            'pageDescription',
            'seoDescription',
            'metaDescription',
            'summary',
            'metadata.description',
            'metadata.seoDescription',
            'seo.description',
            'seo.metaDescription',
        ]);
        if ($description !== null) {
            $metadata['description'] = $description;
        }

        $tableOfContents = $this->firstMetadataValue($rendered, [
            'tableOfContents',
            'toc',
            'contents',
            'pageContents',
            'metadata.tableOfContents',
            'metadata.toc',
            'seo.tableOfContents',
            'seo.toc',
        ]);
        if ($tableOfContents !== null) {
            $metadata['tableOfContents'] = $tableOfContents;
        }

        $customMetadata = is_array($options['seo'] ?? null) ? $options['seo'] : [];

        return array_merge($metadata, $customMetadata);
    }

    /**
     * Build SEO metadata for the source-language page returned by Laravel.
     */
    private function sourcePageSeoMetadata(string $path, array $options): array
    {
        $urlMode = $this->normalizeUrlMode($options['urlMode'] ?? $this->urlMode()) ?? 'path';
        $metadata = [
            'canonicalUrl' => $this->sourcePageUrl($path, $options),
            'robots' => $this->seoRobots(),
            'alternates' => $this->pageAlternates($path, null, $urlMode, $options),
        ];
        $customMetadata = is_array($options['seo'] ?? null) ? $options['seo'] : [];

        return array_merge($metadata, $customMetadata);
    }

    /**
     * Build the complete hreflang set for source and translated page variants.
     */
    private function pageAlternates(string $path, ?string $languageCode, string $urlMode, array $options = [], array $rendered = []): array
    {
        $sourceUrl = $this->safeHttpUrl($rendered['originalUrl'] ?? $rendered['sourceUrl'] ?? null)
            ?? $this->sourcePageUrl($path, $options);
        $sourceLanguage = $this->sourceLanguage();
        $generated = [];

        if ($sourceUrl !== null) {
            $generated[] = [
                'href' => $sourceUrl,
                'hreflang' => $sourceLanguage,
            ];
            $generated[] = [
                'href' => $sourceUrl,
                'hreflang' => 'x-default',
            ];
        }

        $languages = array_values(array_unique(array_filter(array_merge(
            $this->targetLanguages(),
            $languageCode !== null ? [$this->normalizeLanguageCode($languageCode)] : [],
        ))));

        foreach ($languages as $targetLanguage) {
            if ($targetLanguage === $sourceLanguage) {
                continue;
            }

            $href = $languageCode !== null && $targetLanguage === $languageCode
                ? $this->safeHttpUrl($rendered['translatedUrl'] ?? null)
                : null;

            $href ??= $this->localizedPageUrl($path, $targetLanguage, $urlMode, $options);
            if ($href === null) {
                continue;
            }

            $generated[] = [
                'href' => $href,
                'hreflang' => $targetLanguage,
            ];
        }

        return $this->mergeAlternates($generated, $this->renderedAlternates($rendered));
    }

    /**
     * Read alternate links from common render API payload shapes.
     */
    private function renderedAlternates(array $rendered): array
    {
        $alternates = [];
        foreach (['linkTags', 'alternates', 'alternateLinks', 'hreflangs', 'hreflang'] as $key) {
            $items = Arr::get($rendered, $key);
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $language => $item) {
                if (is_array($item)) {
                    $alternates[] = [
                        'href' => $item['href'] ?? $item['url'] ?? null,
                        'hreflang' => $item['hreflang'] ?? $item['hrefLang'] ?? (is_string($language) ? $language : null),
                    ];
                    continue;
                }

                $alternates[] = [
                    'href' => $item,
                    'hreflang' => is_string($language) ? $language : null,
                ];
            }
        }

        return $alternates;
    }

    /**
     * Merge alternates by hreflang while keeping later API values authoritative.
     */
    private function mergeAlternates(array ...$groups): array
    {
        $merged = [];
        foreach ($groups as $group) {
            foreach ($group as $alternate) {
                if (!is_array($alternate)) {
                    continue;
                }

                $href = $this->safeHttpUrl($alternate['href'] ?? null);
                $hreflang = strtolower(trim((string) ($alternate['hreflang'] ?? $alternate['hrefLang'] ?? '')));
                if ($href === null || $hreflang === '') {
                    continue;
                }

                $merged[$hreflang] = [
                    'href' => $href,
                    'hreflang' => $hreflang,
                ];
            }
        }

        return array_values($merged);
    }

    /**
     * Return the first non-empty SEO value from the render payload.
     */
    private function firstMetadataValue(array $rendered, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($rendered, $key);
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value) && $value === []) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * Create a deterministic translated HTML cache key.
     */
    private function cacheKey(string $languageCode, string $path, array $options = []): string
    {
        $siteId = $this->siteId() ?: 'unknown-site';
        $urlMode = (string) ($options['urlMode'] ?? $this->urlMode());
        $query = (string) ($options['query'] ?? '');
        $hash = sha1(json_encode([$siteId, $languageCode, $urlMode, $path, $query, $this->pageHashVersion()], JSON_THROW_ON_ERROR));

        return trim((string) $this->config->get('newtxt.cache_prefix', 'newtxt:rendered-pages'), ':') . ':' . $hash;
    }

    /**
     * Read a rendered HTML snapshot from project-local storage.
     */
    private function localRenderedPageSnapshot(string $languageCode, string $path, array $options = []): ?array
    {
        if (!(bool) $this->config->get('newtxt.store_rendered_pages', true) || !(bool) $this->config->get('newtxt.store_rendered_html', true)) {
            return null;
        }

        $siteId = $this->siteId();
        if ($siteId === '') {
            return null;
        }

        try {
            return $this->snapshots->get(
                $siteId,
                $languageCode,
                (string) ($options['urlMode'] ?? $this->urlMode()),
                $path,
                (string) ($options['query'] ?? ''),
                $this->pageHashVersion(),
            );
        } catch (Throwable) {
            return null;
        }
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
     * Return the current page hash/cache version.
     */
    private function pageHashVersion(): string
    {
        return (string) $this->config->get('newtxt.page_hash_version', 'newtxt-laravel-v2');
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

    private function apiKey(): string
    {
        return trim((string) $this->config->get('newtxt.api_key', ''));
    }

    private function privateKey(): string
    {
        return trim((string) $this->config->get('newtxt.private_key', ''));
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

    private function normalizeUrlMode(mixed $value): ?string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, ['path', 'subdomain'], true) ? $mode : null;
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
     * Normalize language candidates for sitemap output.
     *
     * @return list<string>
     */
    private function sitemapLanguages(mixed $languages = null): array
    {
        $sourceLanguage = $this->sourceLanguage();

        return collect($this->normalizeLanguageList($languages ?? $this->targetLanguages(), true))
            ->filter(fn (string $language): bool => $language !== $sourceLanguage)
            ->unique()
            ->values()
            ->all();
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

    private function normalizeSitemapSiteUrl(mixed $siteUrl): ?string
    {
        $siteUrl = trim((string) $siteUrl);
        if ($siteUrl === '') {
            return null;
        }

        $parts = parse_url($siteUrl);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }

    private function localizedSitemapUrl(string $siteUrl, string $path, string $languageCode, string $urlMode, string $query = ''): string
    {
        $baseUrl = $urlMode === 'subdomain'
            ? $this->languageSubdomainSiteUrl($siteUrl, $languageCode)
            : $siteUrl;
        $localizedPath = $urlMode === 'subdomain'
            ? $this->normalizePath($path)
            : $this->localizedPath($path, $languageCode);
        $url = rtrim($baseUrl, '/') . $localizedPath;

        return $query !== '' ? "{$url}?{$query}" : $url;
    }

    private function sourcePageUrl(string $path, array $options = []): ?string
    {
        $siteUrl = $this->normalizeSitemapSiteUrl($options['siteUrl'] ?? $this->config->get('app.url'));
        if ($siteUrl === null) {
            return null;
        }

        return rtrim($siteUrl, '/') . $this->normalizePath($path);
    }

    private function localizedPageUrl(string $path, string $languageCode, string $urlMode, array $options = []): ?string
    {
        $siteUrl = $this->normalizeSitemapSiteUrl($options['siteUrl'] ?? $this->config->get('app.url'));
        if ($siteUrl === null) {
            return null;
        }

        return $this->localizedSitemapUrl($siteUrl, $path, $languageCode, $urlMode);
    }

    private function localizedPath(string $path, string $languageCode): string
    {
        $path = $this->normalizePath($path);

        return '/' . trim($languageCode, '/') . ($path === '/' ? '' : $path);
    }

    private function languageSubdomainSiteUrl(string $siteUrl, string $languageCode): string
    {
        $parts = parse_url($siteUrl);
        if (!is_array($parts)) {
            return $siteUrl;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return $siteUrl;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return "{$scheme}://{$languageCode}.{$host}{$port}";
    }

    private function sitemapEntryPath(array $entry): ?string
    {
        $loc = trim((string) ($entry['loc'] ?? ''));
        if ($loc === '') {
            return null;
        }

        $query = parse_url($loc, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            return null;
        }

        $path = parse_url($loc, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        if ($this->hasUnsafeUrlPart($path)) {
            return null;
        }

        return $this->normalizePath($path);
    }

    private function isSitemapPathAllowed(string $path): bool
    {
        $normalized = strtolower(ltrim($path, '/'));
        $patterns = (array) $this->config->get('newtxt.excluded_paths', [
            'admin*',
            'api*',
            'auth*',
            'login',
            'logout',
            'register',
            'account*',
            'dashboard*',
            'checkout*',
            'billing*',
            'webhooks*',
        ]);

        foreach ($patterns as $pattern) {
            if (Str::is(strtolower(ltrim((string) $pattern, '/')), $normalized)) {
                return false;
            }
        }

        return true;
    }

    private function sitemapLastModified(mixed $value): string
    {
        $timestamp = is_string($value) && trim($value) !== '' ? strtotime($value) : false;

        return gmdate('c', $timestamp !== false ? $timestamp : time());
    }

    private function hasUnsafeUrlPart(string $value): bool
    {
        return preg_match('/[\r\n#]/', $value) === 1;
    }

    /**
     * Accept only absolute HTTP(S) URLs for generated SEO metadata.
     */
    private function safeHttpUrl(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || trim($host) === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
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
