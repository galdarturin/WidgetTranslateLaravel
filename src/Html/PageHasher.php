<?php

namespace Newtxt\Laravel\Html;

class PageHasher
{
    /**
     * Hash translated text by normalized content.
     *
     * The source hash is stable across whitespace-only changes so local
     * translation memory can be reused for equivalent fragments.
     */
    public function textHash(string $text): string
    {
        return hash('sha256', $this->normalizeText($text));
    }

    /**
     * Hash a full HTML document after lightweight normalization.
     */
    public function htmlHash(string $html): string
    {
        return hash('sha256', $this->normalizeHtml($html));
    }

    /**
     * Hash a rendered page with route and language context.
     */
    public function pageHash(string $siteId, string $languageCode, string $urlMode, string $path, string $html, string $version): string
    {
        return hash('sha256', json_encode([
            'version' => $version,
            'siteId' => $siteId,
            'languageCode' => strtolower(trim($languageCode)),
            'urlMode' => strtolower(trim($urlMode)),
            'path' => '/' . ltrim($path, '/'),
            'htmlHash' => $this->htmlHash($html),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Normalize text fragments before hashing.
     */
    private function normalizeText(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    }

    /**
     * Normalize page HTML before hashing while preserving semantic content.
     */
    private function normalizeHtml(string $html): string
    {
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
        $html = preg_replace('/\s+/u', ' ', trim($html)) ?? trim($html);

        return $html;
    }
}
