<?php

namespace Newtxt\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Newtxt\Laravel\NewtxtManager;
use Throwable;

class RefreshSitemapCommand extends Command
{
    protected $signature = 'newtxt:sitemap-refresh
        {--register-robots : Append the public sitemap URL to public/robots.txt without replacing existing directives.}';

    protected $description = 'Regenerate the local translated-page sitemap and optionally register it in robots.txt.';

    /**
     * Rebuild the sitemap and optionally add its public URL to robots.txt.
     */
    public function handle(NewtxtManager $newtxt, Filesystem $files): int
    {
        if (!(bool) config('newtxt.sitemap_enabled', true)) {
            $this->error('The translated-page sitemap is disabled in the NewTXT configuration.');

            return self::FAILURE;
        }

        try {
            $sitemap = $newtxt->refreshSitemap();
        } catch (Throwable) {
            $this->error('Unable to generate the translated-page sitemap.');

            return self::FAILURE;
        }

        $this->info("Generated {$sitemap['count']} translated sitemap URLs.");
        $this->line("Local sitemap: {$sitemap['path']}");

        $sitemapUrl = $this->publicSitemapUrl();

        if (!$this->option('register-robots')) {
            $this->line('Public sitemap: ' . $sitemapUrl);

            return self::SUCCESS;
        }

        if (!$this->isPublicHttpUrl($sitemapUrl)) {
            $this->error('The application URL must be an absolute public HTTP(S) URL before updating robots.txt.');

            return self::FAILURE;
        }

        $robotsPath = public_path('robots.txt');
        $contents = $files->exists($robotsPath) ? $files->get($robotsPath) : '';
        $directive = 'Sitemap: ' . $sitemapUrl;

        if ($this->containsDirective($contents, $directive)) {
            $this->info('robots.txt already declares the NewTXT sitemap.');

            return self::SUCCESS;
        }

        $contents = trim($contents);
        if ($contents === '') {
            $contents = 'User-agent: *';
        }
        $contents .= "\n" . $directive . "\n";

        $files->ensureDirectoryExists(dirname($robotsPath));
        $this->write($files, $robotsPath, $contents);
        $this->info('Added the NewTXT sitemap directive to public/robots.txt.');

        return self::SUCCESS;
    }

    /**
     * Build the public sitemap URL from trusted application configuration.
     */
    private function publicSitemapUrl(): string
    {
        $configuredSiteUrl = trim((string) config('newtxt.sitemap_site_url', ''));
        $siteUrl = $configuredSiteUrl !== '' ? $configuredSiteUrl : trim((string) config('app.url', ''));
        $parts = parse_url($siteUrl);

        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = route('newtxt.sitemap', [], false);

        return $scheme !== '' && $host !== '' ? "{$scheme}://{$host}{$port}{$path}" : '';
    }

    /**
     * Check the generated sitemap URL before writing a public crawler directive.
     */
    private function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.test')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
    }

    /**
     * Avoid adding a duplicate sitemap directive with different casing.
     */
    private function containsDirective(string $contents, string $directive): bool
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (strcasecmp(trim($line), $directive) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update robots.txt atomically while preserving all existing directives.
     */
    private function write(Filesystem $files, string $path, string $contents): void
    {
        $temporaryPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $files->put($temporaryPath, $contents);
        $files->move($temporaryPath, $path);
    }
}
