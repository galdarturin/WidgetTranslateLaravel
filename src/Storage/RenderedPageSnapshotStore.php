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
        $siteId = $this->sanitizePathPart($siteId);
        $languageCode = $this->sanitizePathPart($languageCode);
        $directory = $this->pageDirectory($siteId, $languageCode);
        $indexPath = $this->indexPath(
            $siteId,
            $languageCode,
            $urlMode,
            $path,
            $query,
            $version,
        );
        $index = $this->readJson($indexPath);

        if ($this->files->exists($indexPath)) {
            $this->files->delete($indexPath);
        }

        $pageHash = $this->sanitizePathPart((string) ($index['pageHash'] ?? ''));
        if ($pageHash !== 'unknown' && !$this->isPageHashIndexed($directory, $pageHash)) {
            $this->deleteSnapshotArtifact($directory, $pageHash);
        }
    }

    /**
     * Report or delete unindexed rendered page artifacts older than the limit.
     */
    public function pruneStaleArtifacts(int $olderThanSeconds, bool $delete = false, ?string $siteId = null, ?array $languageCodes = null): array
    {
        $basePath = $this->basePath() . '/pages';
        if (!$this->files->isDirectory($basePath)) {
            return [
                'scanned' => 0,
                'stale' => 0,
                'deleted' => 0,
                'bytes' => 0,
            ];
        }

        $cutoff = time() - max(0, $olderThanSeconds);
        $allowedLanguages = $this->languageFilter($languageCodes);
        $summary = [
            'scanned' => 0,
            'stale' => 0,
            'deleted' => 0,
            'bytes' => 0,
        ];

        foreach ($this->siteDirectories($basePath, $siteId) as $siteDirectory) {
            foreach ($this->files->directories($siteDirectory) as $languageDirectory) {
                $safeLanguageCode = basename($languageDirectory);
                if ($allowedLanguages !== null && !isset($allowedLanguages[$safeLanguageCode])) {
                    continue;
                }

                $indexedHashes = $this->indexedPageHashes($languageDirectory);
                foreach ($this->files->files($languageDirectory) as $file) {
                    if (strtolower($file->getExtension()) !== 'json') {
                        continue;
                    }

                    $summary['scanned']++;
                    $metadata = $this->readJson($file->getPathname()) ?? [];
                    $pageHash = $this->sanitizePathPart((string) ($metadata['pageHash'] ?? $file->getFilenameWithoutExtension()));
                    if (isset($indexedHashes[$pageHash])) {
                        continue;
                    }

                    if ($this->snapshotUpdatedAt($metadata, $file->getPathname()) > $cutoff) {
                        continue;
                    }

                    $summary['stale']++;
                    $summary['bytes'] += $this->snapshotArtifactBytes($languageDirectory, $pageHash, $metadata);

                    if ($delete) {
                        $this->deleteSnapshotArtifact($languageDirectory, $pageHash);
                        $summary['deleted']++;
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * List stored rendered page snapshot metadata for sitemap integrations.
     *
     * @param  list<string>|null  $languageCodes
     * @return list<array<string,mixed>>
     */
    public function allForSitemap(?string $siteId = null, ?array $languageCodes = null): array
    {
        $basePath = $this->basePath() . '/pages';
        if (!$this->files->isDirectory($basePath)) {
            return [];
        }

        $allowedLanguages = $this->languageFilter($languageCodes);
        $siteDirectories = $this->siteDirectories($basePath, $siteId);
        $snapshots = [];

        foreach ($siteDirectories as $siteDirectory) {
            $safeSiteId = basename($siteDirectory);

            foreach ($this->files->directories($siteDirectory) as $languageDirectory) {
                $safeLanguageCode = basename($languageDirectory);
                if ($allowedLanguages !== null && !isset($allowedLanguages[$safeLanguageCode])) {
                    continue;
                }

                foreach ($this->files->files($languageDirectory) as $file) {
                    if (strtolower($file->getExtension()) !== 'json') {
                        continue;
                    }

                    $metadata = $this->readJson($file->getPathname());
                    if (!$this->isSitemapSnapshot($metadata, $languageDirectory)) {
                        continue;
                    }

                    $metadata['siteId'] = (string) ($metadata['siteId'] ?? $safeSiteId);
                    $metadata['languageCode'] = $this->normalizeLanguageCode((string) ($metadata['languageCode'] ?? $safeLanguageCode));
                    $metadata['path'] = $this->normalizePath((string) $metadata['path']);
                    $metadata['query'] = $this->normalizeQuery((string) ($metadata['query'] ?? ''));
                    $metadata['urlMode'] = strtolower(trim((string) $metadata['urlMode']));

                    $snapshots[] = $metadata;
                }
            }
        }

        usort($snapshots, static function (array $left, array $right): int {
            return [
                (string) ($left['languageCode'] ?? ''),
                (string) ($left['path'] ?? ''),
                (string) ($left['query'] ?? ''),
                (string) ($left['pageHash'] ?? ''),
            ] <=> [
                (string) ($right['languageCode'] ?? ''),
                (string) ($right['path'] ?? ''),
                (string) ($right['query'] ?? ''),
                (string) ($right['pageHash'] ?? ''),
            ];
        });

        return $snapshots;
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
        $version = (string) ($metadata['pageHashVersion'] ?? $this->config->get('newtxt.page_hash_version', 'newtxt-laravel-v3'));
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
     * @return list<string>
     */
    private function siteDirectories(string $basePath, ?string $siteId): array
    {
        $siteId = trim((string) $siteId);
        if ($siteId !== '') {
            $directory = $basePath . '/' . $this->sanitizePathPart($siteId);

            return $this->files->isDirectory($directory) ? [$directory] : [];
        }

        return $this->files->directories($basePath);
    }

    /**
     * @param  list<string>|null  $languageCodes
     * @return array<string,true>|null
     */
    private function languageFilter(?array $languageCodes): ?array
    {
        if ($languageCodes === null) {
            return null;
        }

        $filter = [];
        foreach ($languageCodes as $languageCode) {
            $normalized = $this->normalizeLanguageCode((string) $languageCode);
            if ($normalized !== 'unknown') {
                $filter[$normalized] = true;
            }
        }

        return $filter;
    }

    private function isSitemapSnapshot(?array $metadata, string $languageDirectory): bool
    {
        $htmlPath = trim((string) ($metadata['htmlPath'] ?? ''));
        $htmlFullPath = $htmlPath !== '' ? $languageDirectory . '/' . basename($htmlPath) : '';

        return is_array($metadata) &&
            trim((string) ($metadata['pageHash'] ?? '')) !== '' &&
            trim((string) ($metadata['languageCode'] ?? '')) !== '' &&
            trim((string) ($metadata['path'] ?? '')) !== '' &&
            trim((string) ($metadata['urlMode'] ?? '')) !== '' &&
            ($metadata['htmlStored'] ?? false) === true &&
            $htmlFullPath !== '' &&
            $this->files->exists($htmlFullPath) &&
            trim((string) $this->files->get($htmlFullPath)) !== '';
    }

    /**
     * Return page hashes currently referenced by request indexes.
     */
    private function indexedPageHashes(string $languageDirectory): array
    {
        $indexDirectory = $languageDirectory . '/indexes';
        if (!$this->files->isDirectory($indexDirectory)) {
            return [];
        }

        $hashes = [];
        foreach ($this->files->files($indexDirectory) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            $pageHash = $this->sanitizePathPart((string) (($this->readJson($file->getPathname()) ?? [])['pageHash'] ?? ''));
            if ($pageHash !== 'unknown') {
                $hashes[$pageHash] = true;
            }
        }

        return $hashes;
    }

    /**
     * Check whether a snapshot artifact is still referenced by any index.
     */
    private function isPageHashIndexed(string $languageDirectory, string $pageHash): bool
    {
        return isset($this->indexedPageHashes($languageDirectory)[$this->sanitizePathPart($pageHash)]);
    }

    /**
     * Delete snapshot metadata and HTML files for one page hash.
     */
    private function deleteSnapshotArtifact(string $languageDirectory, string $pageHash): void
    {
        foreach (["{$pageHash}.json", "{$pageHash}.html"] as $fileName) {
            $path = $languageDirectory . '/' . basename($fileName);
            if ($this->files->exists($path)) {
                $this->files->delete($path);
            }
        }
    }

    /**
     * Return the best available update timestamp for pruning decisions.
     */
    private function snapshotUpdatedAt(array $metadata, string $metadataPath): int
    {
        foreach (['updatedAt', 'storedAt'] as $key) {
            $timestamp = strtotime((string) ($metadata[$key] ?? ''));
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return $this->files->lastModified($metadataPath);
    }

    /**
     * Count metadata and HTML bytes for one snapshot artifact.
     */
    private function snapshotArtifactBytes(string $languageDirectory, string $pageHash, array $metadata): int
    {
        $bytes = 0;
        foreach (["{$pageHash}.json", basename((string) ($metadata['htmlPath'] ?? "{$pageHash}.html"))] as $fileName) {
            $path = $languageDirectory . '/' . $fileName;
            if ($this->files->exists($path)) {
                $bytes += $this->files->size($path);
            }
        }

        return $bytes;
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
        $version ??= (string) $this->config->get('newtxt.page_hash_version', 'newtxt-laravel-v3');
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
     * Normalize language codes for artifact directory names.
     */
    private function normalizeLanguageCode(string $languageCode): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', strtolower(trim($languageCode))) ?: 'unknown';
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
