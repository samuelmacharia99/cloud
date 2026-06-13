<?php

namespace Tests\Unit\Helpers;

use Tests\TestCase;

class ProductDescriptionHelperTest extends TestCase
{
    public function test_br_tags_render_as_line_breaks(): void
    {
        $html = format_product_description('Performance optimized<br>Built for speed');

        $this->assertStringContainsString('Performance optimized<br>', $html);
        $this->assertStringContainsString('Built for speed', $html);
    }

    public function test_unsafe_html_is_stripped(): void
    {
        $html = format_product_description('<script>alert(1)</script>Safe text');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Safe text', $html);
    }

    public function test_newlines_render_as_line_breaks(): void
    {
        $html = format_product_description("Line one\nLine two");

        $this->assertStringContainsString("Line one<br>\nLine two", $html);
    }
}
