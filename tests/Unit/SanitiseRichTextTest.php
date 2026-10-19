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

    public function test_it_gives_a_bare_host_the_scheme_it_left_out(): void
    {
        // What someone actually types into a link: the host, with no https:// in
        // front of it. Stored as written it is a path, and the browser resolves it
        // against the page — so on /sales/12 this link leads to /sales/www.google.com.
        $clean = $this->action->handle('<p><a href="www.google.com">Katalog</a></p>');

        $this->assertStringContainsString('href="https://www.google.com"', $clean);
    }

    public function test_it_leaves_words_that_only_look_like_urls_alone(): void
    {
        // Text is not an attribute, and a sentence must not sprout links.
        $this->assertSame(
            '<p>antar ke gudang 2, NOTE: www.example.co.id/pcs</p>',
            $this->action->handle('<p>antar ke gudang 2, NOTE: www.example.co.id/pcs</p>')
        );

        // No dot, no host: a relative path, and it is already a correct one.
        $this->assertStringContainsString('href="/sales/1"', $this->action->handle('<a href="/sales/1">rel</a>'));
        $this->assertStringContainsString('href="#top"', $this->action->handle('<a href="#top">top</a>'));
        $this->assertStringContainsString('href="mailto:a@b.co"', $this->action->handle('<a href="mailto:a@b.co">m</a>'));
    }

    public function test_it_makes_a_bare_host_absolute_in_an_image_too(): void
    {
        $clean = $this->action->handle('<img src="www.contoh.co/img.png" alt="Nota">');

        $this->assertStringContainsString('src="https://www.contoh.co/img.png"', $clean);
    }

    public function test_it_returns_the_note_untouched_when_there_is_nothing_to_make_absolute(): void
    {
        // Byte-identical, not merely equivalent: a note that needs no repair comes
        // back as it went in, so the repair migration cannot rewrite rows it has
        // no reason to touch. Asserted on absoluteUrls rather than handle, because
        // handle is allowed to change the markup — that is the purifier's half.
        $note = '<p>bukti <a href="https://google.com" rel="noreferrer noopener">k</a></p>';

        $this->assertSame($note, $this->action->absoluteUrls($note));

        // And it is a fixed point: repairing an already-repaired note is a no-op,
        // which is what makes the migration safe to re-run.
        $this->assertSame($note, $this->action->absoluteUrls($this->action->absoluteUrls($note)));
    }

    public function test_it_keeps_non_ascii_text_intact_while_doing_it(): void
    {
        // libxml reads a fragment as ISO-8859-1 unless it is told otherwise, and
        // the mangling is silent — this is the assertion that catches it.
        $clean = $this->action->handle('<p>Café — bukti <a href="www.contoh.co">kirim</a></p>');

        $this->assertStringContainsString('Café — bukti', $clean);
        $this->assertStringContainsString('href="https://www.contoh.co"', $clean);
    }

    public function test_it_still_removes_javascript_links_after_making_urls_absolute(): void
    {
        // The normalisation runs before the purifier, so a scheme this adds is
        // still checked against the allow-list. This is the order that says so.
        $clean = $this->action->handle('<a href="javascript:alert(1)">click</a>');

        $this->assertStringNotContainsString('javascript:', $clean);
    }

    public function test_it_returns_an_empty_string_for_empty_input(): void
    {
        $this->assertSame('', $this->action->handle(null));
        $this->assertSame('', $this->action->handle(''));
        $this->assertSame('', $this->action->handle('   '));
    }
}
