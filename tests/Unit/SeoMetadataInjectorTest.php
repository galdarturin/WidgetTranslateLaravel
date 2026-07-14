<?php

namespace Newtxt\Laravel\Tests\Unit;

use Newtxt\Laravel\Html\SeoMetadataInjector;
use PHPUnit\Framework\TestCase;

class SeoMetadataInjectorTest extends TestCase
{
    public function test_it_upserts_safe_seo_metadata(): void
    {
        $injector = new SeoMetadataInjector();

        $html = $injector->apply('<html><head><title>Demo</title></head><body>Hello</body></html>', [
            'canonicalUrl' => 'https://example.test/fr/about',
            'robots' => 'index,follow',
            'alternates' => [
                ['href' => 'https://example.test/de/about', 'hreflang' => 'de'],
            ],
            'title' => 'Translated title',
            'description' => 'Translated description',
        ]);

        $this->assertStringContainsString('rel="canonical"', $html);
        $this->assertStringContainsString('href="https://example.test/fr/about"', $html);
        $this->assertStringContainsString('property="og:url"', $html);
        $this->assertStringContainsString('name="twitter:url"', $html);
        $this->assertStringContainsString('name="robots"', $html);
        $this->assertStringContainsString('hreflang="de"', $html);
        $this->assertStringContainsString('Translated description', $html);
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
