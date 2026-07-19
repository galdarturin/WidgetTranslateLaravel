<?php

namespace Newtxt\Laravel\Tests\Unit;

use Newtxt\Laravel\Html\SeoMetadataExtractor;
use PHPUnit\Framework\TestCase;

class SeoMetadataExtractorTest extends TestCase
{
    public function test_it_extracts_existing_text_seo_metadata_from_head(): void
    {
        $extractor = new SeoMetadataExtractor();

        $metadata = $extractor->extract(
            '<html><head><title>Source title</title><meta name="description" content="Source description"><meta name="keywords" content="source, seo"><meta property="og:title" content="Source OG"><meta property="og:description" content="Source OG description"><meta name="twitter:title" content="Source Twitter"><meta name="twitter:description" content="Source Twitter description"><meta name="robots" content="index,follow"><link rel="canonical" href="https://example.test/about"></head><body>Body</body></html>',
        );

        $this->assertSame([
            'title' => 'Source title',
            'description' => 'Source description',
            'keywords' => 'source, seo',
            'openGraphTitle' => 'Source OG',
            'openGraphDescription' => 'Source OG description',
            'twitterTitle' => 'Source Twitter',
            'twitterDescription' => 'Source Twitter description',
        ], $metadata);
        $this->assertArrayNotHasKey('robots', $metadata);
        $this->assertArrayNotHasKey('canonicalUrl', $metadata);
    }
}
