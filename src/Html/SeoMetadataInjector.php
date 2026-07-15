<?php

namespace Newtxt\Laravel\Html;

use DOMDocument;
use DOMElement;
use DOMXPath;

class SeoMetadataInjector
{
    /**
     * Add missing SEO tags to a rendered HTML document.
     *
     * DOMDocument is used instead of regex so native page metadata is preserved
     * while missing canonical, hreflang, Open Graph, or robots tags are added.
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
        $metadataTitle = $this->textValue($metadata['title'] ?? $metadata['pageTitle'] ?? null);
        $title = $this->existingTitle($document);
        if ($title === '') {
            $title = $metadataTitle !== '' ? $metadataTitle : $this->firstHeading($document);
        }

        if ($title !== '') {
            $this->insertTitleIfMissing($document, $head, $title);
        }

        $metadataCanonicalUrl = $this->safeHttpUrl($metadata['canonicalUrl'] ?? null);
        $canonicalUrl = $this->existingCanonicalUrl($head) ?? $metadataCanonicalUrl;
        if ($metadataCanonicalUrl !== null) {
            $this->insertCanonicalLinkIfMissing($document, $head, $metadataCanonicalUrl);
        }

        if ($canonicalUrl !== null) {
            $this->insertMetaIfMissing($document, $head, 'property', 'og:url', $canonicalUrl);
            $this->insertMetaIfMissing($document, $head, 'name', 'twitter:url', $canonicalUrl);
        }

        $alternates = $this->normalizedAlternates($metadata['alternates'] ?? []);
        if ($alternates !== []) {
            $this->insertMissingAlternateLinks($document, $head, $alternates);
        }

        $robots = trim((string) ($metadata['robots'] ?? ''));
        if ($robots !== '') {
            $this->insertMetaIfMissing($document, $head, 'name', 'robots', $robots);
        }

        $description = $this->existingMetaContent($head, 'name', 'description');
        if ($description === '') {
            $description = $this->textValue(
                $metadata['description']
                    ?? $metadata['metaDescription']
                    ?? $metadata['summary']
                    ?? null,
            );
        }
        if ($description === '') {
            $description = $this->pageDescription($document);
        }

        $tableOfContents = $this->tableOfContentsValue(
            $metadata['tableOfContents']
                ?? $metadata['toc']
                ?? $metadata['contents']
                ?? null,
        );
        if ($tableOfContents === '') {
            $tableOfContents = $this->headingTableOfContents($document);
        }

        foreach ([
            ['name', 'description', $description],
            ['property', 'og:title', $title],
            ['property', 'og:description', $description],
            ['name', 'twitter:title', $title],
            ['name', 'twitter:description', $description],
            ['name', 'newtxt:table-of-contents', $tableOfContents],
        ] as [$attribute, $key, $value]) {
            $value = $this->textValue($value);
            if ($value !== '') {
                $this->insertMetaIfMissing($document, $head, $attribute, $key, $value);
            }
        }

        $result = $document->saveHTML();
        if (!is_string($result)) {
            return $html;
        }

        return preg_replace('/^<\?xml encoding="UTF-8"\?>\s*/', '', $result) ?? $result;
    }

    /**
     * Insert a document title when the page does not already define one.
     */
    private function insertTitleIfMissing(DOMDocument $document, DOMElement $head, string $title): void
    {
        $titleElement = $head->getElementsByTagName('title')->item(0);
        if ($titleElement instanceof DOMElement && $this->textValue($titleElement->textContent) !== '') {
            return;
        }

        if (!$titleElement instanceof DOMElement) {
            $titleElement = $document->createElement('title');
            $head->insertBefore($titleElement, $head->firstChild);
        }

        $titleElement->appendChild($document->createTextNode($title));
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
     * Insert a canonical link when the page does not already define one.
     */
    private function insertCanonicalLinkIfMissing(DOMDocument $document, DOMElement $head, string $href): void
    {
        foreach ($head->getElementsByTagName('link') as $link) {
            if (!$link instanceof DOMElement || strcasecmp($link->getAttribute('rel'), 'canonical') !== 0) {
                continue;
            }

            if (trim($link->getAttribute('href')) === '') {
                $link->setAttribute('href', $href);
            }

            return;
        }

        $link = $document->createElement('link');
        $link->setAttribute('rel', 'canonical');
        $link->setAttribute('href', $href);
        $head->appendChild($link);
    }

    /**
     * Insert missing hreflang alternate tags while preserving native tags.
     */
    private function insertMissingAlternateLinks(DOMDocument $document, DOMElement $head, array $alternates): void
    {
        foreach ($alternates as $alternate) {
            $this->insertLinkIfMissing($document, $head, 'alternate', $alternate['href'], $alternate['hreflang']);
        }
    }

    /**
     * Insert link tags by rel and optional hreflang when absent.
     */
    private function insertLinkIfMissing(DOMDocument $document, DOMElement $head, string $rel, string $href, ?string $hreflang = null): void
    {
        foreach ($head->getElementsByTagName('link') as $link) {
            if (!$link instanceof DOMElement || strcasecmp($link->getAttribute('rel'), $rel) !== 0) {
                continue;
            }

            if ($hreflang !== null && strcasecmp($link->getAttribute('hreflang'), $hreflang) !== 0) {
                continue;
            }

            if (trim($link->getAttribute('href')) === '') {
                $link->setAttribute('href', $href);
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
     * Normalize alternate link metadata into unique hreflang entries.
     */
    private function normalizedAlternates(mixed $alternates): array
    {
        $normalized = [];
        foreach ((array) $alternates as $alternate) {
            if (!is_array($alternate)) {
                continue;
            }

            $href = $this->safeHttpUrl($alternate['href'] ?? null);
            $hreflang = strtolower(trim((string) ($alternate['hreflang'] ?? $alternate['hrefLang'] ?? '')));
            if ($href === null || $hreflang === '') {
                continue;
            }

            $normalized[$hreflang] = [
                'href' => $href,
                'hreflang' => $hreflang,
            ];
        }

        return array_values($normalized);
    }

    /**
     * Insert meta tags by name/property key when absent.
     */
    private function insertMetaIfMissing(DOMDocument $document, DOMElement $head, string $attribute, string $key, string $content): void
    {
        foreach ($head->getElementsByTagName('meta') as $meta) {
            if (!$meta instanceof DOMElement || strcasecmp($meta->getAttribute($attribute), $key) !== 0) {
                continue;
            }

            if (trim($meta->getAttribute('content')) === '') {
                $meta->setAttribute('content', $content);
            }

            return;
        }

        $meta = $document->createElement('meta');
        $meta->setAttribute($attribute, $key);
        $meta->setAttribute('content', $content);
        $head->appendChild($meta);
    }

    /**
     * Return the existing HTML title as plain text.
     */
    private function existingTitle(DOMDocument $document): string
    {
        $title = $document->getElementsByTagName('title')->item(0);

        return $title instanceof DOMElement ? $this->textValue($title->textContent) : '';
    }

    /**
     * Return an existing canonical URL when it is safe to reuse.
     */
    private function existingCanonicalUrl(DOMElement $head): ?string
    {
        foreach ($head->getElementsByTagName('link') as $link) {
            if ($link instanceof DOMElement && strcasecmp($link->getAttribute('rel'), 'canonical') === 0) {
                return $this->safeHttpUrl($link->getAttribute('href'));
            }
        }

        return null;
    }

    /**
     * Return existing meta content by attribute key.
     */
    private function existingMetaContent(DOMElement $head, string $attribute, string $key): string
    {
        foreach ($head->getElementsByTagName('meta') as $meta) {
            if ($meta instanceof DOMElement && strcasecmp($meta->getAttribute($attribute), $key) === 0) {
                return $this->textValue($meta->getAttribute('content'));
            }
        }

        return '';
    }

    /**
     * Convert headings into a compact table-of-contents meta value.
     */
    private function headingTableOfContents(DOMDocument $document): string
    {
        $items = [];
        $headings = (new DOMXPath($document))->query('//h1|//h2|//h3');
        if ($headings === false) {
            return '';
        }

        foreach ($headings as $heading) {
            if (!$heading instanceof DOMElement) {
                continue;
            }

            $text = $this->textValue($heading->textContent, 120);
            if ($text !== '') {
                $items[] = $text;
            }

            if (count($items) >= 12) {
                break;
            }
        }

        return $this->textValue(implode(' | ', $items), 700);
    }

    /**
     * Return the first visible page heading for generated title fallback.
     */
    private function firstHeading(DOMDocument $document): string
    {
        $headings = (new DOMXPath($document))->query('//h1|//h2|//h3');
        if ($headings === false) {
            return '';
        }

        foreach ($headings as $heading) {
            if (!$heading instanceof DOMElement) {
                continue;
            }

            $text = $this->textValue($heading->textContent, 180);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * Build a compact description from page copy when metadata is missing.
     */
    private function pageDescription(DOMDocument $document): string
    {
        $paragraphs = (new DOMXPath($document))->query('//main//p|//article//p|//p');
        if ($paragraphs !== false) {
            foreach ($paragraphs as $paragraph) {
                if (!$paragraph instanceof DOMElement) {
                    continue;
                }

                $text = $this->textValue($paragraph->textContent, 180);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        $body = $document->getElementsByTagName('body')->item(0);

        return $body instanceof DOMElement ? $this->textValue($body->textContent, 180) : '';
    }

    /**
     * Normalize a table-of-contents string or structured list.
     */
    private function tableOfContentsValue(mixed $value): string
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                $text = $this->tableOfContentsItemText($item);
                if ($text !== '') {
                    $items[] = $text;
                }
            }

            return $this->textValue(implode(' | ', $items), 700);
        }

        return $this->textValue($value, 700);
    }

    /**
     * Extract one table-of-contents item from common API shapes.
     */
    private function tableOfContentsItemText(mixed $item): string
    {
        if (is_array($item)) {
            foreach (['title', 'text', 'label', 'heading'] as $key) {
                $text = $this->textValue($item[$key] ?? null, 120);
                if ($text !== '') {
                    return $text;
                }
            }

            return '';
        }

        return $this->textValue($item, 120);
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

    /**
     * Normalize safe plain-text metadata values.
     */
    private function textValue(mixed $value, int $limit = 500): string
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
