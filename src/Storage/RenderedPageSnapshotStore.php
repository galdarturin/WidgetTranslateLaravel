<?php

namespace Newtxt\Laravel\Storage;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;

class RenderedPageSnapshotStore
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Store page hash metadata and optionally full HTML.
     */
    public function put(array $snapshot, bool $storeHtml): void
    {
        $siteId = $this->sanitizePathPart((string) ($snapshot['siteId'] ?? $this->config->get('newtxt.site_id', 'site')));
        $languageCode = $this->sanitizePathPart((string) ($snapshot['languageCode'] ?? 'source'));
        $pageHash = $this->sanitizePathPart((string) ($snapshot['pageHash'] ?? 'unknown'));
        $directory = $this->pageDirectory($siteId, $languageCode);

        $this->files->ensureDirectoryExists($directory);

        $html = (string) ($snapshot['html'] ?? '');
        $metadata = $snapshot;
        unset($metadata['html']);
        $metadata['htmlStored'] = $storeHtml && $html !== '';
        $metadata['htmlPath'] = $metadata['htmlStored'] ? "{$pageHash}.html" : null;
        $metadata['updatedAt'] = gmdate('c');

        $this->write($directory . "/{$pageHash}.json", json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if ($metadata['htmlStored']) {
            $this->write($directory . "/{$pageHash}.html", $html);
        }

        $this->putIndex($siteId, $languageCode, $metadata);
    }

    /**
     * Read a stored rendered page snapshot by request identity.
     */
    public function get(string $siteId, string $languageCode, string $urlMode, string $path, string $query = '', ?string $version = null): ?array
    {
        $siteId = $this->sanitizePathPart($siteId);
        $languageCode = $this->sanitizePathPart($languageCode);
        $directory = $this->pageDirectory($siteId, $languageCode);
        $index = $this->readJson($this->indexPath($siteId, $languageCode, $urlMode, $path, $query, $version));
        if ($index === null) {
            return null;
        }

        $pageHash = trim((string) ($index['pageHash'] ?? ''));
        if ($pageHash === '') {
            return null;
        }
        $pageHash = $this->sanitizePathPart($pageHash);

        $metadata = $this->readJson($directory . "/{$pageHash}.json");
        if ($metadata === null || !($metadata['htmlStored'] ?? false)) {
            return null;
        }

        $htmlPath = $directory . "/{$pageHash}.html";
        if (!$this->files->exists($htmlPath)) {
            return null;
        }

        $html = $this->files->get($htmlPath);
        if (!is_string($html) || trim($html) === '') {
            return null;
        }

        $metadata['html'] = $html;
        $metadata['fromLocalSnapshot'] = true;
        $metadata['cacheSource'] = 'local-snapshot';

        return $metadata;
    }

    /**
     * Remove the local request index for a rendered page snapshot.
     */
    public function forget(string $siteId, string $languageCode, string $urlMode, string $path, string $query = '', ?string $version = null): void
    {
        $indexPath = $this->indexPath(
            $this->sanitizePathPart($siteId),
            $this->sanitizePathPart($languageCode),
            $urlMode,
            $path,
            $query,
            $version,
        );

        if ($this->files->exists($indexPath)) {
            $this->files->delete($indexPath);
        }
    }

    /**
     * Store the request identity index used for fast local reads.
     */
    private function putIndex(string $siteId, string $languageCode, array $metadata): void
    {
        $path = (string) ($metadata['path'] ?? '');
        $urlMode = (string) ($metadata['urlMode'] ?? '');
        if ($path === '' || $urlMode === '') {
            return;
        }

        $query = (string) ($metadata['query'] ?? '');
        $version = (string) ($metadata['pageHashVersion'] ?? $this->config->get('newtxt.page_hash_version', 'newtxt-laravel-v1'));
        $pageHash = trim((string) ($metadata['pageHash'] ?? ''));
        if ($pageHash === '') {
            return;
        }

        $index = [
            'siteId' => $siteId,
            'languageCode' => $languageCode,
            'urlMode' => $urlMode,
            'path' => $this->normalizePath($path),
            'query' => $this->normalizeQuery($query),
            'pageHash' => $this->sanitizePathPart($pageHash),
            'pageHashVersion' => $version,
            'htmlStored' => (bool) ($metadata['htmlStored'] ?? false),
            'updatedAt' => gmdate('c'),
        ];

        $this->write(
            $this->indexPath($siteId, $languageCode, $urlMode, $path, $query, $version),
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Read one JSON file into an associative array.
     */
    private function readJson(string $path): ?array
    {
        if (!$this->files->exists($path)) {
            return null;
        }

        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Write one artifact file.
     */
    private function write(string $path, string $contents): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $tmpPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $this->files->put($tmpPath, $contents);
        $this->files->move($tmpPath, $path);
    }

    /**
     * Build the language-scoped page artifact directory.
     */
    private function pageDirectory(string $siteId, string $languageCode): string
    {
        return $this->basePath() . "/pages/{$siteId}/{$languageCode}";
    }

    /**
     * Build the request identity index path.
     */
    private function indexPath(string $siteId, string $languageCode, string $urlMode, string $path, string $query = '', ?string $version = null): string
    {
        $version ??= (string) $this->config->get('newtxt.page_hash_version', 'newtxt-laravel-v1');
        $payload = [
            $siteId,
            $languageCode,
            strtolower(trim($urlMode)),
            $this->normalizePath($path),
            $this->normalizeQuery($query),
            $version,
        ];
        $lookupHash = sha1(json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->pageDirectory($siteId, $languageCode) . "/indexes/{$lookupHash}.json";
    }

    /**
     * Normalize paths before storing lookup metadata.
     */
    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');

        return $path === '//' ? '/' : $path;
    }

    /**
     * Normalize query strings before storing lookup metadata.
     */
    private function normalizeQuery(string $query): string
    {
        return ltrim(trim($query), '?');
    }

    /**
     * Return the package artifact root.
     */
    private function basePath(): string
    {
        return rtrim((string) $this->config->get('newtxt.storage_path', storage_path('app/newtxt')), '/');
    }

    /**
     * Keep filesystem path parts safe.
     */
    private function sanitizePathPart(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', trim($value)) ?: 'unknown';
    }
}
