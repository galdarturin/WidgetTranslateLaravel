<?php

namespace Newtxt\Laravel\Tests\Unit;

use Newtxt\Laravel\Html\PageHasher;
use PHPUnit\Framework\TestCase;

class PageHasherTest extends TestCase
{
    public function test_text_hash_is_stable_across_whitespace_only_changes(): void
    {
        $hasher = new PageHasher();

        $this->assertSame(
            $hasher->textHash("Hello\nworld"),
            $hasher->textHash(' Hello   world ')
        );
    }

    public function test_page_hash_changes_when_route_context_changes(): void
    {
        $hasher = new PageHasher();

        $this->assertNotSame(
            $hasher->pageHash('site-a', 'fr', 'path', '/about', '<html>Hello</html>', 'v1'),
            $hasher->pageHash('site-a', 'de', 'path', '/about', '<html>Hello</html>', 'v1')
        );
    }
}
