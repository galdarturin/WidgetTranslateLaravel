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
        $directory = $this->basePath() . "/pages/{$siteId}/{$languageCode}";

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
    }

    /**
     * Write one artifact file.
     */
    private function write(string $path, string $contents): void
    {
        $tmpPath = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $this->files->put($tmpPath, $contents);
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
     * Keep filesystem path parts safe.
     */
    private function sanitizePathPart(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '_', trim($value)) ?: 'unknown';
    }
}
