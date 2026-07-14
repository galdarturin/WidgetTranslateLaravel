<?php

namespace Newtxt\Laravel\Html;

use DOMDocument;
use DOMElement;

class SeoMetadataInjector
{
    /**
     * Upsert SEO tags in a rendered translated HTML document.
     *
     * DOMDocument is used instead of regex so existing tags are updated without
     * duplicating canonical, Open Graph, or robots metadata.
     */
    public function apply(string $html, array $metadata): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $head = $this->ensureHead($document);
        $canonicalUrl = $this->safeHttpUrl($metadata['canonicalUrl'] ?? null);
        if ($canonicalUrl !== null) {
            $this->upsertLink($document, $head, 'canonical', $canonicalUrl);
            $this->upsertMeta($document, $head, 'property', 'og:url', $canonicalUrl);
            $this->upsertMeta($document, $head, 'name', 'twitter:url', $canonicalUrl);
        }

        foreach (($metadata['alternates'] ?? []) as $alternate) {
            if (!is_array($alternate)) {
                continue;
            }

            $href = $this->safeHttpUrl($alternate['href'] ?? null);
            $hreflang = trim((string) ($alternate['hreflang'] ?? $alternate['hrefLang'] ?? ''));
            if ($href !== null && $hreflang !== '') {
                $this->upsertLink($document, $head, 'alternate', $href, $hreflang);
            }
        }

        $robots = trim((string) ($metadata['robots'] ?? ''));
        if ($robots !== '') {
            $this->upsertMeta($document, $head, 'name', 'robots', $robots);
        }

        foreach ([
            ['name', 'description', $metadata['description'] ?? null],
            ['property', 'og:title', $metadata['title'] ?? null],
            ['property', 'og:description', $metadata['description'] ?? null],
            ['name', 'twitter:title', $metadata['title'] ?? null],
            ['name', 'twitter:description', $metadata['description'] ?? null],
        ] as [$attribute, $key, $value]) {
            $value = trim((string) $value);
            if ($value !== '') {
                $this->upsertMeta($document, $head, $attribute, $key, $value);
            }
        }

        $result = $document->saveHTML();
        if (!is_string($result)) {
            return $html;
        }

        return preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/', '', $result) ?? $result;
    }

    /**
     * Ensure the document has a head element.
     */
    private function ensureHead(DOMDocument $document): DOMElement
    {
        $head = $document->getElementsByTagName('head')->item(0);
        if ($head instanceof DOMElement) {
            return $head;
        }

        $html = $document->getElementsByTagName('html')->item(0);
        if (!$html instanceof DOMElement) {
            $html = $document->appendChild($document->createElement('html'));
        }

        $head = $document->createElement('head');
        $html->insertBefore($head, $html->firstChild);

        return $head;
    }

    /**
     * Upsert link tags by rel and optional hreflang.
     */
    private function upsertLink(DOMDocument $document, DOMElement $head, string $rel, string $href, ?string $hreflang = null): void
    {
        foreach ($head->getElementsByTagName('link') as $link) {
            if (!$link instanceof DOMElement || strcasecmp($link->getAttribute('rel'), $rel) !== 0) {
                continue;
            }

            if ($hreflang !== null && strcasecmp($link->getAttribute('hreflang'), $hreflang) !== 0) {
                continue;
            }

            $link->setAttribute('href', $href);
            if ($hreflang !== null) {
                $link->setAttribute('hreflang', $hreflang);
            }
            return;
        }

        $link = $document->createElement('link');
        $link->setAttribute('rel', $rel);
        $link->setAttribute('href', $href);
        if ($hreflang !== null) {
            $link->setAttribute('hreflang', $hreflang);
        }
        $head->appendChild($link);
    }

    /**
     * Upsert meta tags by name/property key.
     */
    private function upsertMeta(DOMDocument $document, DOMElement $head, string $attribute, string $key, string $content): void
    {
        foreach ($head->getElementsByTagName('meta') as $meta) {
            if ($meta instanceof DOMElement && strcasecmp($meta->getAttribute($attribute), $key) === 0) {
                $meta->setAttribute('content', $content);
                return;
            }
        }

        $meta = $document->createElement('meta');
        $meta->setAttribute($attribute, $key);
        $meta->setAttribute('content', $content);
        $head->appendChild($meta);
    }

    /**
     * Accept only absolute HTTP(S) URLs in SEO tags.
     */
    private function safeHttpUrl(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || trim($host) === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }
}
