<?php

namespace Newtxt\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Newtxt\Laravel\NewtxtManager;
use SimpleXMLElement;
use Throwable;

class PrewarmCommand extends Command
{
    private const MaxSitemapBytes = 2_000_000;

    protected $signature = 'newtxt:prewarm
        {--language=* : Target language code. Defaults to configured target languages.}
        {--path=* : Source page path to prewarm.}
        {--sitemap= : Public sitemap URL to read for source paths.}
        {--force : Bypass local rendered HTML cache and request a fresh render.}';

    protected $description = 'Prewarm local translated HTML cache for one or more public pages.';

    /**
     * Render configured pages into the local Laravel cache.
     */
    public function handle(NewtxtManager $newtxt): int
    {
        $languages = $this->resolveLanguages($newtxt);
        $paths = $this->resolvePaths();

        if (!$languages) {
            $this->error('No languages were provided. Use --language or configure target languages in the NewTXT dashboard.');
            return self::FAILURE;
        }

        if (!$paths) {
            $this->error('No paths were found. Use --path or provide a readable sitemap.');
            return self::FAILURE;
        }

        $rendered = 0;
        $storedTranslations = 0;
        $syncTranslations = (bool) config('newtxt.sync_hashed_translations_on_prewarm', true);
        foreach ($languages as $language) {
            foreach ($paths as $path) {
                try {
                    $result = $newtxt->rememberRenderedPage($language, $path, [
                        'forceRefreshCache' => (bool) $this->option('force'),
                    ]);
                    if (is_array($result) && isset($result['html'])) {
                        $rendered++;
                        $this->line("Prewarmed {$language} {$path}");
                        if ($syncTranslations) {
                            try {
                                $count = $newtxt->syncHashedTranslations($language, $path);
                                $storedTranslations += $count;
                                $this->line("Stored {$count} hashed translations for {$language} {$path}");
                            } catch (Throwable $syncError) {
                                $this->warn("Translation sync skipped for {$language} {$path}: {$syncError->getMessage()}");
                            }
                        }
                    } else {
                        $this->warn("Skipped {$language} {$path}");
                    }
                } catch (Throwable $error) {
                    $this->warn("Failed {$language} {$path}: {$error->getMessage()}");
                }
            }
        }

        $this->info("Prewarm completed. Rendered entries: {$rendered}. Hashed translations stored: {$storedTranslations}.");

        return self::SUCCESS;
    }

    /**
     * Resolve language options into normalized language codes.
     */
    private function resolveLanguages(NewtxtManager $newtxt): array
    {
        $languages = $this->option('language') ?: $newtxt->targetLanguages();

        return collect($languages)
            ->map(fn ($language) => strtolower(trim((string) $language)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Resolve source paths from command options and optional sitemap XML.
     */
    private function resolvePaths(): array
    {
        $paths = collect($this->option('path'))
            ->map(fn ($path) => $this->normalizePath((string) $path))
            ->filter();

        $sitemapUrl = (string) $this->option('sitemap');
        if ($sitemapUrl !== '') {
            $paths = $paths->merge($this->readSitemapPaths($sitemapUrl));
        }

        return $paths->unique()->values()->all();
    }

    /**
     * Read sitemap URLs after basic SSRF checks.
     */
    private function readSitemapPaths(string $sitemapUrl): array
    {
        if (!$this->isAllowedSitemapUrl($sitemapUrl)) {
            $this->warn('Sitemap URL was rejected by safety checks.');
            return [];
        }

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->accept('application/xml,text/xml;q=0.9,*/*;q=0.1')
                ->get($sitemapUrl)
                ->throw();

            $body = $response->body();
            if (strlen($body) > self::MaxSitemapBytes) {
                $this->warn('Sitemap response is larger than the configured safety limit.');
                return [];
            }

            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
            if (!$xml instanceof SimpleXMLElement) {
                return [];
            }

            return collect($xml->url ?? [])
                ->map(fn ($entry) => (string) ($entry->loc ?? ''))
                ->filter()
                ->map(fn ($url) => parse_url($url, PHP_URL_PATH) ?: '/')
                ->map(fn ($path) => $this->normalizePath((string) $path))
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $error) {
            $this->warn("Unable to read sitemap: {$error->getMessage()}");
            return [];
        }
    }

    /**
     * Allow only public HTTP(S) sitemap hosts.
     */
    private function isAllowedSitemapUrl(string $sitemapUrl): bool
    {
        $parts = parse_url($sitemapUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        $ip = gethostbyname($host);
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Normalize paths before prewarm calls.
     */
    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');

        return $path === '//' ? '/' : $path;
    }
}
