<?php

namespace Newtxt\Laravel\Tests\Unit;

use Newtxt\Laravel\Html\SeoMetadataInjector;
use PHPUnit\Framework\TestCase;

class SeoMetadataInjectorTest extends TestCase
{
    public function test_it_adds_only_missing_safe_seo_metadata(): void
    {
        $injector = new SeoMetadataInjector();

        $html = $injector->apply(
            '<html><head><title>Demo</title><meta name="description" content="Native description"><link rel="canonical" href="https://old.example.test/about"><link rel="alternate" hreflang="fr" href="https://old.example.test/fr/about"><link rel="alternate" hreflang="it" href="https://old.example.test/it/about"><link rel="alternate" type="application/rss+xml" href="https://example.test/feed"></head><body><h1>Overview</h1><h2>Details</h2>Hello</body></html>',
            [
                'canonicalUrl' => 'https://example.test/fr/about',
                'robots' => 'index,follow',
                'alternates' => [
                    ['href' => 'https://example.test/about', 'hreflang' => 'en'],
                    ['href' => 'https://example.test/about', 'hreflang' => 'x-default'],
                    ['href' => 'https://example.test/fr/about', 'hreflang' => 'fr'],
                    ['href' => 'https://example.test/de/about', 'hreflang' => 'de'],
                ],
                'title' => 'Translated title',
                'description' => 'Translated description',
                'tableOfContents' => [
                    ['title' => 'Translated overview'],
                    ['text' => 'Translated details'],
                ],
            ],
        );

        $this->assertSame(1, substr_count($html, 'rel="canonical"'));
        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('href="https://old.example.test/about"', $html);
        $this->assertStringContainsString('property="og:url"', $html);
        $this->assertStringContainsString('content="https://old.example.test/about"', $html);
        $this->assertStringContainsString('name="twitter:url"', $html);
        $this->assertStringContainsString('name="robots"', $html);
        $this->assertStringContainsString('<title>Demo</title>', $html);
        $this->assertStringNotContainsString('<title>Translated title</title>', $html);
        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertStringContainsString('hreflang="fr"', $html);
        $this->assertSame(1, substr_count($html, 'hreflang="fr"'));
        $this->assertStringContainsString('hreflang="de"', $html);
        $this->assertStringContainsString('hreflang="it"', $html);
        $this->assertStringContainsString('type="application/rss+xml"', $html);
        $this->assertStringContainsString('Native description', $html);
        $this->assertStringNotContainsString('Translated description', $html);
        $this->assertStringContainsString('name="newtxt:table-of-contents"', $html);
        $this->assertStringContainsString('Translated overview | Translated details', $html);
    }

    public function test_it_derives_missing_source_page_title_and_description(): void
    {
        $injector = new SeoMetadataInjector();

        $html = $injector->apply('<html><head></head><body><main><h1>Native heading</h1><p>Native paragraph description for the default page.</p></main></body></html>', [
            'canonicalUrl' => 'https://example.test/about',
            'robots' => 'index,follow',
            'alternates' => [
                ['href' => 'https://example.test/about', 'hreflang' => 'en'],
                ['href' => 'https://example.test/about', 'hreflang' => 'x-default'],
                ['href' => 'https://example.test/fr/about', 'hreflang' => 'fr'],
            ],
        ]);

        $this->assertStringContainsString('<title>Native heading</title>', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.test/about">', $html);
        $this->assertStringContainsString('<meta name="description" content="Native paragraph description for the default page.">', $html);
        $this->assertStringContainsString('hreflang="x-default"', $html);
        $this->assertStringContainsString('hreflang="fr"', $html);
    }

    public function test_it_rejects_unsafe_seo_urls(): void
    {
        $injector = new SeoMetadataInjector();

        $html = $injector->apply('<html><head></head><body>Hello</body></html>', [
            'canonicalUrl' => 'javascript:alert(1)',
            'alternates' => [
                ['href' => 'https:///missing-host', 'hreflang' => 'fr'],
            ],
        ]);

        $this->assertStringNotContainsString('javascript:alert', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('rel="alternate"', $html);
    }
}
