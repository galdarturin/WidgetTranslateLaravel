<?php

namespace Newtxt\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Newtxt\Laravel\NewtxtManager;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ServeNewtxtTranslatedPages
{
    public function __construct(private readonly NewtxtManager $newtxt)
    {
    }

    /**
     * Serve translated HTML for safe public page requests.
     *
     * The middleware intentionally skips unsafe methods, authenticated users,
     * excluded paths, and non-HTML requests to avoid caching private or mutable
     * responses.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->shouldProcessPage($request)) {
            return $next($request);
        }

        $translationContext = $this->translatedRequestContext($request);
        if ($translationContext === null) {
            $response = $next($request);
            $this->recordSourceResponse($request, $response);

            return $this->applySourceSeoMetadata($request, $response);
        }

        [$languageCode, $sourcePath] = $translationContext;
        $rendered = $this->newtxt->canRenderTranslatedPages()
            ? $this->newtxt->rememberRenderedPage($languageCode, $sourcePath, [
                'query' => $request->getQueryString() ?? '',
            ])
            : null;

        if (!is_array($rendered) || !$this->newtxt->isRenderedPageReady($rendered, $languageCode)) {
            $response = $next($request);

            return $this->applyIncompleteTranslatedPageSeo($response, $languageCode, $sourcePath);
        }

        $response = response($rendered['html'], 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-NewTXT-Cache', $this->cacheHeader($rendered))
            ->header('X-NewTXT-Translation-Status', 'ready');

        foreach (['pageHash' => 'X-NewTXT-Page-Hash', 'htmlHash' => 'X-NewTXT-Html-Hash'] as $key => $header) {
            if (isset($rendered[$key]) && is_string($rendered[$key]) && $rendered[$key] !== '') {
                $response->header($header, $rendered[$key]);
            }
        }

        return $response;
    }

    /**
     * Resolve translated request language and source path.
     *
     * Some host applications rewrite localized paths before route middleware
     * runs. In that case, trusted request attributes can carry the language
     * while the current request path already points at the source page.
     *
     * @return array{0:string,1:string}|null
     */
    private function translatedRequestContext(Request $request): ?array
    {
        $attributeLanguage = $this->languageFromRequestAttributes($request);
        if ($attributeLanguage !== null) {
            return [$attributeLanguage, '/' . ltrim($request->path(), '/')];
        }

        $languageCode = $this->newtxt->extractLanguageFromPath($request->path());
        if ($languageCode === null) {
            return null;
        }

        return [
            $languageCode,
            $this->newtxt->sourcePathForTranslatedPath($request->path(), $languageCode),
        ];
    }

    /**
     * Read a route-safe language code from configured request attributes.
     */
    private function languageFromRequestAttributes(Request $request): ?string
    {
        $targetLanguages = $this->newtxt->targetLanguages();

        foreach ((array) config('newtxt.request_language_attributes', []) as $attribute) {
            $attribute = trim((string) $attribute);
            if ($attribute === '') {
                continue;
            }

            $candidate = $request->attributes->get($attribute);
            if (!is_scalar($candidate)) {
                continue;
            }

            $languageCode = strtolower(str_replace('_', '-', trim((string) $candidate)));
            if (preg_match('/^[a-z0-9][a-z0-9_-]{1,19}$/', $languageCode) !== 1) {
                continue;
            }

            if ($targetLanguages === [] || in_array($languageCode, $targetLanguages, true)) {
                return $languageCode;
            }
        }

        return null;
    }

    /**
     * Expose the cache source without leaking internal storage paths.
     */
    private function cacheHeader(array $rendered): string
    {
        if (
            in_array($rendered['cacheSource'] ?? null, ['local-snapshot', 'laravel-cache'], true)
            || ($rendered['fromLocalSnapshot'] ?? false) === true
            || ($rendered['fromLocalCache'] ?? false) === true
        ) {
            return 'local-hit';
        }

        return ($rendered['fromCache'] ?? false) ? 'remote-hit' : 'remote-miss';
    }

    /**
     * Check request-level safety before any remote call or cache lookup.
     */
    private function shouldProcessPage(Request $request): bool
    {
        if (!$this->newtxt->enabled()) {
            return false;
        }

        if (!$request->isMethod('GET') && !$request->isMethod('HEAD')) {
            return false;
        }

        if (Auth::check()) {
            return false;
        }

        if (!$request->acceptsHtml()) {
            return false;
        }

        foreach ((array) config('newtxt.excluded_paths', []) as $pattern) {
            if ($pattern !== '' && $request->is($pattern)) {
                return false;
            }
        }

        return !Str::startsWith($request->path(), ['_', '.']);
    }

    /**
     * Keep incomplete localized URLs out of search indexes.
     */
    private function applyIncompleteTranslatedPageSeo(
        Response $response,
        string $languageCode,
        string $sourcePath,
    ): Response {
        $response->headers->set('X-NewTXT-Translation-Status', 'incomplete');
        $response->headers->set('X-Robots-Tag', 'noindex, follow');

        if (!$this->isHtmlResponse($response)) {
            return $response;
        }

        $content = $response->getContent();
        if (!is_string($content) || trim($content) === '') {
            return $response;
        }

        try {
            $html = $this->newtxt->applyIncompleteTranslatedPageSeo(
                $languageCode,
                $sourcePath,
                $content,
            );
        } catch (Throwable) {
            return $response;
        }

        if ($html !== $content) {
            $response->setContent($html);
            $response->headers->remove('Content-Length');
        }

        return $response;
    }

    /**
     * Persist the source page hash for public HTML responses.
     */
    private function recordSourceResponse(Request $request, Response $response): void
    {
        if (!$request->isMethod('GET') || !$this->isHtmlResponse($response)) {
            return;
        }

        $content = $response->getContent();
        if (!is_string($content) || trim($content) === '') {
            return;
        }

        try {
            $this->newtxt->recordSourcePage('/' . ltrim($request->path(), '/'), $content, [
                'query' => $request->getQueryString() ?? '',
            ]);
        } catch (Throwable) {
            // Source-page snapshot failures must not break customer pages.
        }
    }

    /**
     * Inject source-page SEO tags only into safe public HTML responses.
     */
    private function applySourceSeoMetadata(Request $request, Response $response): Response
    {
        if (!$request->isMethod('GET') || !$this->isHtmlResponse($response)) {
            return $response;
        }

        $content = $response->getContent();
        if (!is_string($content) || trim($content) === '') {
            return $response;
        }

        try {
            $html = $this->newtxt->applySourcePageSeo('/' . ltrim($request->path(), '/'), $content, [
                'query' => $request->getQueryString() ?? '',
            ]);
        } catch (Throwable) {
            return $response;
        }

        if ($html !== $content) {
            $response->setContent($html);
            $response->headers->remove('Content-Length');
        }

        return $response;
    }

    /**
     * Detect cacheable HTML responses without parsing response bodies.
     */
    private function isHtmlResponse(Response $response): bool
    {
        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));

        return str_contains($contentType, 'text/html');
    }
}
