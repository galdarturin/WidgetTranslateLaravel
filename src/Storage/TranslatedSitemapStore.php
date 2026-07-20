<?php

namespace Newtxt\Laravel\Storage;

use DOMDocument;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use OverflowException;

class TranslatedSitemapStore
{
    private const SitemapNamespace = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public function __construct(
        private readonly Filesystem $files,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Build and atomically store a sitemap containing translated page URLs.
     *
     * @param  list<array<string,mixed>>  $entries
     * @return array{xml:string,path:string,count:int,etag:string,lastModified:int}
     */
    public function put(array $entries): array
    {
        $entries = $this->normalizeEntries($entries);
        $maximumUrls = max(1, min(50_000, (int) $this->config->get('newtxt.sitemap_max_urls', 50_000)));

        if (count($entries) > $maximumUrls) {
            throw new OverflowException('Translated sitemap URL limit exceeded');
        }

        $xml = $this->buildXml($entries);
        $maximumBytes = max(1024, min(50_000_000, (int) $this->config->get('newtxt.sitemap_max_bytes', 50_000_000)));
        if (strlen($xml) > $maximumBytes) {
            throw new OverflowException('Translated sitemap size limit exceeded');
        }
        $path = $this->path();

        $this->files->ensureDirectoryExists(dirname($path));
        if (!$this->files->exists($path) || $this->files->get($path) !== $xml) {
            $this->write($path, $xml);
        }

        return [
            'xml' => $xml,
            'path' => $path,
            'count' => count($entries),
            'etag' => hash('sha256', $xml),
            'lastModified' => $this->files->lastModified($path),
        ];
    }

    /**
     * Return the local generated sitemap path.
     */
    public function path(): string
    {
        $basePath = rtrim((string) $this->config->get('newtxt.storage_path', storage_path('app/newtxt')), '/');

        return $basePath . '/sitemaps/translate-sitemap.xml';
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array{loc:string,lastmod:?string}>
     */
    private function normalizeEntries(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $location = trim((string) ($entry['loc'] ?? ''));
            $parts = parse_url($location);
            if (
                $location === ''
                || strlen($location) > 2048
                || !is_array($parts)
                || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || trim((string) ($parts['host'] ?? '')) === ''
            ) {
                continue;
            }

            $lastModified = trim((string) ($entry['lastmod'] ?? ''));
            $timestamp = $lastModified !== '' ? strtotime($lastModified) : false;

            $normalized[$location] = [
                'loc' => $location,
                'lastmod' => $timestamp !== false ? gmdate('c', $timestamp) : null,
            ];
        }

        ksort($normalized, SORT_STRING);

        return array_values($normalized);
    }

    /**
     * @param  list<array{loc:string,lastmod:?string}>  $entries
     */
    private function buildXml(array $entries): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $urlset = $document->createElementNS(self::SitemapNamespace, 'urlset');
        $document->appendChild($urlset);

        foreach ($entries as $entry) {
            $url = $document->createElement('url');
            $url->appendChild($document->createElement('loc'))->appendChild(
                $document->createTextNode($entry['loc']),
            );

            if ($entry['lastmod'] !== null) {
                $url->appendChild($document->createElement('lastmod'))->appendChild(
                    $document->createTextNode($entry['lastmod']),
                );
            }

            $urlset->appendChild($url);
        }

        return $document->saveXML() ?: '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    }

    /**
     * Write a generated artifact without exposing a partially written file.
     */
    private function write(string $path, string $contents): void
    {
        $temporaryPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $this->files->put($temporaryPath, $contents);
        $this->files->move($temporaryPath, $path);
    }
}
