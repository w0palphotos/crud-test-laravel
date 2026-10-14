<?php

namespace Tests\Unit;

use App\Actions\SanitiseRichText;
use Tests\TestCase;

class SanitiseRichTextTest extends TestCase
{
    private SanitiseRichText $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new SanitiseRichText;
    }

    public function test_it_keeps_the_formatting_a_sales_note_needs(): void
    {
        $html = '<p>Follow up with <strong>Dimas</strong> about the <em>retailer</em> order.</p>'
            .'<ul><li>Discount agreed</li><li>Ship from gudang 2</li></ul>';

        $clean = $this->action->handle($html);

        $this->assertStringContainsString('<strong>Dimas</strong>', $clean);
        $this->assertStringContainsString('<em>retailer</em>', $clean);
        $this->assertStringContainsString('<li>Discount agreed</li>', $clean);
    }

    public function test_it_keeps_links_that_a_salesperson_would_paste(): void
    {
        $clean = $this->action->handle(
            '<p><a href="https://contoh.co/katalog" target="_blank">Catalogue</a></p>'
        );

        $this->assertStringContainsString('href="https://contoh.co/katalog"', $clean);
    }

    public function test_it_keeps_images_uploaded_through_the_app(): void
    {
        $clean = $this->action->handle(
            '<p><img src="https://cdn.contoh.co/uploads/abc.png" alt="Nota"></p>'
        );

        $this->assertStringContainsString('src="https://cdn.contoh.co/uploads/abc.png"', $clean);
        $this->assertStringContainsString('alt="Nota"', $clean);
    }

    public function test_it_removes_script_tags(): void
    {
        $clean = $this->action->handle('<p>ok</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('alert(1)', $clean);
    }

    public function test_it_removes_event_handler_attributes(): void
    {
        $clean = $this->action->handle('<img src="https://cdn.contoh.co/a.png" onerror="alert(1)">');

        $this->assertStringNotContainsString('onerror', $clean);
    }

    public function test_it_removes_javascript_links(): void
    {
        $clean = $this->action->handle('<a href="javascript:alert(1)">click</a>');

        $this->assertStringNotContainsString('javascript:', $clean);
    }

    public function test_it_removes_data_uri_images(): void
    {
        $clean = $this->action->handle('<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">');

        $this->assertStringNotContainsString('data:text/html', $clean);
    }

    public function test_it_removes_iframes(): void
    {
        $clean = $this->action->handle('<iframe src="https://evil.example"></iframe>');

        $this->assertStringNotContainsString('<iframe', $clean);
    }

    public function test_it_removes_svg_payloads(): void
    {
        $clean = $this->action->handle('<svg><g/onload=alert(1)></g></svg>');

        $this->assertStringNotContainsString('<svg', $clean);
        $this->assertStringNotContainsString('onload', $clean);
    }

    public function test_it_removes_style_attributes(): void
    {
        $clean = $this->action->handle('<p style="position:fixed;top:0;left:0;width:100vw">overlay</p>');

        $this->assertStringNotContainsString('style=', $clean);
        $this->assertStringContainsString('overlay', $clean);
    }

    public function test_it_disarms_an_obfuscated_javascript_link(): void
    {
        // HTMLPurifier's answer here is to escape the characters that make the
        // scheme readable rather than to delete the whole href, so the string
        // "alert(1)" survives in a form that is not an executable URL. What
        // matters is that the href is not a javascript: URL once decoded — the
        // browser will not percent-decode before it parses the scheme.
        $clean = $this->action->handle('<a href="jav&#x09;ascript:alert(1)">click</a>');

        $href = $this->hrefOf($clean);

        $this->assertNotNull($href);
        $this->assertStringNotContainsStringIgnoringCase('javascript', $this->decodeHref($href));
    }

    /**
     * The decoded href a browser would actually act on.
     *
     * @param  string  $href  Raw attribute value straight out of the purifier.
     */
    private function decodeHref(string $href): string
    {
        return rawurldecode(html_entity_decode($href, ENT_QUOTES));
    }

    private function hrefOf(string $html): ?string
    {
        return preg_match('/href="([^"]*)"/', $html, $matches) === 1 ? $matches[1] : null;
    }

    public function test_it_returns_an_empty_string_for_empty_input(): void
    {
        $this->assertSame('', $this->action->handle(null));
        $this->assertSame('', $this->action->handle(''));
        $this->assertSame('', $this->action->handle('   '));
    }
}
