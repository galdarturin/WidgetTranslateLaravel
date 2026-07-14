<?php

namespace Newtxt\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Newtxt\Laravel\Tests\TestCase;

class WidgetDirectiveTest extends TestCase
{
    public function test_blade_directive_renders_configured_widget_script(): void
    {
        config()->set('newtxt.enabled', true);
        config()->set('newtxt.public_key', 'public-widget-key');
        config()->set('newtxt.widget_loader_url', 'https://cdn.example.test/widget/v1/loader.js');
        config()->set('newtxt.navigation_mode', 'replace');
        config()->set('newtxt.private_key', 'server-private-key');
        config()->set('newtxt.api_key', 'server-api-key');

        $html = Blade::render('@newtxtWidget()');

        $this->assertStringContainsString('src="https://cdn.example.test/widget/v1/loader.js"', $html);
        $this->assertStringContainsString('data-site-key="public-widget-key"', $html);
        $this->assertStringContainsString('data-navigation-mode="replace"', $html);
        $this->assertStringNotContainsString('server-private-key', $html);
        $this->assertStringNotContainsString('server-api-key', $html);
    }

    public function test_blade_directive_returns_empty_output_when_disabled(): void
    {
        config()->set('newtxt.enabled', false);
        config()->set('newtxt.public_key', 'public-widget-key');
        config()->set('newtxt.widget_loader_url', 'https://cdn.example.test/widget/v1/loader.js');

        $this->assertSame('', Blade::render('@newtxtWidget()'));
    }
}
