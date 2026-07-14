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
        if (!$this->shouldAttemptTranslation($request)) {
            return $next($request);
        }

        $languageCode = $this->newtxt->extractLanguageFromPath($request->path());
        if ($languageCode === null) {
            $response = $next($request);
            $this->recordSourceResponse($request, $response);

            return $response;
        }

        $sourcePath = $this->newtxt->sourcePathForTranslatedPath($request->path(), $languageCode);
        $rendered = $this->newtxt->rememberRenderedPage($languageCode, $sourcePath, [
            'query' => $request->getQueryString() ?? '',
        ]);

        if (!is_array($rendered) || !isset($rendered['html'])) {
            return $next($request);
        }

        $response = response($rendered['html'], 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-NewTXT-Cache', $this->cacheHeader($rendered));

        foreach (['pageHash' => 'X-NewTXT-Page-Hash', 'htmlHash' => 'X-NewTXT-Html-Hash'] as $key => $header) {
            if (isset($rendered[$key]) && is_string($rendered[$key]) && $rendered[$key] !== '') {
                $response->header($header, $rendered[$key]);
            }
        }

        return $response;
    }

    /**
     * Expose the cache source without leaking internal storage paths.
     */
    private function cacheHeader(array $rendered): string
    {
        if (($rendered['cacheSource'] ?? null) === 'local-snapshot' || ($rendered['fromLocalSnapshot'] ?? false) === true) {
            return 'local-hit';
        }

        return ($rendered['fromCache'] ?? false) ? 'remote-hit' : 'remote-miss';
    }

    /**
     * Check request-level safety before any remote call or cache lookup.
     */
    private function shouldAttemptTranslation(Request $request): bool
    {
        if (!$this->newtxt->canServeTranslatedPages()) {
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
     * Detect cacheable HTML responses without parsing response bodies.
     */
    private function isHtmlResponse(Response $response): bool
    {
        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));

        return str_contains($contentType, 'text/html');
    }
}
