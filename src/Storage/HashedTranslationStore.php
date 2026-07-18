<?php

namespace Newtxt\Laravel\Storage;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Newtxt\Laravel\Html\PageHasher;

class HashedTranslationStore
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ConfigRepository $config,
        private readonly PageHasher $hasher,
    ) {
    }

    /**
     * Store one translated fragment by source-content hash.
     */
    public function put(string $languageCode, string $sourceText, string $translatedText, array $metadata = []): array
    {
        $languageCode = $this->normalizeLanguageCode($languageCode);
        $sourceHash = $this->hasher->textHash($sourceText);
        $translationHash = $this->hasher->textHash($translatedText);
        $siteId = (string) ($metadata['siteId'] ?? $this->config->get('newtxt.site_id', ''));
        $entry = [
            'siteId' => $siteId,
            'languageCode' => $languageCode,
            'sourceHash' => $sourceHash,
            'translationHash' => $translationHash,
            'sourceText' => $sourceText,
            'translatedText' => $translatedText,
            'metadata' => $metadata,
            'updatedAt' => gmdate('c'),
        ];

        $this->writeJson($this->entryPath($languageCode, $sourceHash, $siteId), $entry);

        return $entry;
    }

    /**
     * Store translated nodes returned by the NewTXT page translation API.
     */
    public function putNodes(string $languageCode, array $nodes, array $metadata = []): int
    {
        if (!(bool) $this->config->get('newtxt.store_hashed_translations', true)) {
            return 0;
        }

        $stored = 0;
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $sourceText = trim((string) ($node['sourceText'] ?? ''));
            $translatedText = trim((string) ($node['translatedText'] ?? ''));
            if ($sourceText === '' || $translatedText === '') {
                continue;
            }

            $this->put($languageCode, $sourceText, $translatedText, array_merge($metadata, [
                'nodeKey' => $node['nodeKey'] ?? null,
                'nodeType' => $node['nodeType'] ?? null,
                'source' => $node['source'] ?? null,
            ]));
            $stored++;
        }

        return $stored;
    }

    /**
     * Resolve a translated fragment by source text.
     */
    public function get(string $languageCode, string $sourceText, ?string $siteId = null): ?array
    {
        $path = $this->entryPath(
            $this->normalizeLanguageCode($languageCode),
            $this->hasher->textHash($sourceText),
            (string) ($siteId ?? $this->config->get('newtxt.site_id', '')),
        );

        if (!$this->files->exists($path)) {
            return null;
        }

        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Report or delete hash-addressed translation entries older than the limit.
     */
    public function pruneStaleArtifacts(int $olderThanSeconds, bool $delete = false, ?string $siteId = null, ?array $languageCodes = null): array
    {
        $basePath = $this->basePath() . '/translations';
        if (!$this->files->isDirectory($basePath)) {
            return [
                'scanned' => 0,
                'stale' => 0,
                'deleted' => 0,
                'bytes' => 0,
            ];
        }

        $cutoff = time() - max(0, $olderThanSeconds);
        $sites = $siteId !== null && trim($siteId) !== ''
            ? [$basePath . '/' . $this->siteId($siteId)]
            : $this->files->directories($basePath);
        $allowedLanguages = $this->languageFilter($languageCodes);
        $summary = [
            'scanned' => 0,
            'stale' => 0,
            'deleted' => 0,
            'bytes' => 0,
        ];

        foreach ($sites as $siteDirectory) {
            if (!$this->files->isDirectory($siteDirectory)) {
                continue;
            }

            foreach ($this->files->directories($siteDirectory) as $languageDirectory) {
                if ($allowedLanguages !== null && !isset($allowedLanguages[basename($languageDirectory)])) {
                    continue;
                }

                foreach ($this->files->files($languageDirectory) as $file) {
                    if (strtolower($file->getExtension()) !== 'json') {
                        continue;
                    }

                    $summary['scanned']++;
                    $metadata = json_decode($this->files->get($file->getPathname()), true);
                    $updatedAt = is_array($metadata) ? strtotime((string) ($metadata['updatedAt'] ?? '')) : false;
                    $lastChanged = $updatedAt !== false ? $updatedAt : $this->files->lastModified($file->getPathname());
                    if ($lastChanged > $cutoff) {
                        continue;
                    }

                    $summary['stale']++;
                    $summary['bytes'] += $this->files->size($file->getPathname());

                    if ($delete) {
                        $this->files->delete($file->getPathname());
                        $summary['deleted']++;
                    }
                }
            }
        }

        return $summary;
    }

    /**
     * Build the full local path for a translation entry.
     */
    private function entryPath(string $languageCode, string $sourceHash, string $siteId): string
    {
        return $this->basePath() . "/translations/{$this->siteId($siteId)}/{$languageCode}/{$sourceHash}.json";
    }

    /**
     * Write JSON atomically enough for normal deploy/runtime usage.
     */
    private function writeJson(string $path, array $payload): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $tmpPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $this->files->put($tmpPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->files->move($tmpPath, $path);
    }

    /**
     * Return the package artifact root.
     */
    private function basePath(): string
    {
        return rtrim((string) $this->config->get('newtxt.storage_path', storage_path('app/newtxt')), '/');
    }

    /**
     * Normalize the site ID for filesystem paths.
     */
    private function siteId(string $siteId): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', $siteId !== '' ? $siteId : 'site') ?: 'site';
    }

    /**
     * Normalize language code for filesystem paths.
     */
    private function normalizeLanguageCode(string $languageCode): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', strtolower(trim($languageCode))) ?: 'unknown';
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
}
