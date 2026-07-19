<?php

namespace Newtxt\Laravel\Html;

use DOMDocument;
use DOMElement;

class SeoMetadataExtractor
{
    /**
     * Extract text-bearing SEO metadata from a source HTML document.
     */
    public function extract(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return [];
        }

        $head = $document->getElementsByTagName('head')->item(0);
        if (!$head instanceof DOMElement) {
            return [];
        }

        $metadata = [];
        $title = $document->getElementsByTagName('title')->item(0);
        if ($title instanceof DOMElement) {
            $value = $this->textValue($title->textContent, 512);
            if ($value !== '') {
                $metadata['title'] = $value;
            }
        }

        foreach ($this->metaDefinitions() as $field => [$attribute, $key, $limit]) {
            $value = $this->metaContent($head, $attribute, $key, $limit);
            if ($value !== '') {
                $metadata[$field] = $value;
            }
        }

        return $metadata;
    }

    /**
     * Return the supported text metadata fields.
     */
    private function metaDefinitions(): array
    {
        return [
            'description' => ['name', 'description', 1024],
            'keywords' => ['name', 'keywords', 1024],
            'openGraphTitle' => ['property', 'og:title', 512],
            'openGraphDescription' => ['property', 'og:description', 1024],
            'twitterTitle' => ['name', 'twitter:title', 512],
            'twitterDescription' => ['name', 'twitter:description', 1024],
        ];
    }

    /**
     * Read the first matching meta content value from the document head.
     */
    private function metaContent(DOMElement $head, string $attribute, string $key, int $limit): string
    {
        foreach ($head->getElementsByTagName('meta') as $meta) {
            if (!$meta instanceof DOMElement || strcasecmp($meta->getAttribute($attribute), $key) !== 0) {
                continue;
            }

            $value = $this->textValue($meta->getAttribute('content'), $limit);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Normalize safe plain-text metadata values.
     */
    private function textValue(mixed $value, int $limit): string
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return '';
        }

        $text = trim(strip_tags((string) $value));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit);
        }

        return substr($text, 0, $limit);
    }
}
