<?php

namespace App\Actions;

use DOMDocument;
use DOMElement;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitises the rich-text a salesperson types into a note.
 *
 * Without this, `v-html` in the browser is a stored-XSS hole: anyone who can
 * save a sale note can run script in every admin's session. The editor constrains
 * what a well-behaved user produces, which is not the same thing — pasted markup
 * arrives already formed and bypasses the editor entirely.
 *
 * Purifying at write time rather than at render time is deliberate. It costs a
 * few milliseconds once, and it means the stored value is already safe, so every
 * future reader is safe by default — including ones nobody has thought about yet.
 * The alternative has to be remembered at each new render site, and a forgotten
 * one is a vulnerability rather than an error.
 *
 * The trade-off is that sanitising twice is not free and not lossless, so this
 * must not be run over content that is already known-clean on every read. It
 * belongs on the way in.
 */
final class SanitiseRichText
{
    /**
     * Reuse the compiled purifier across requests on the same worker, which
     * rebuilds its definition files on construction and costs noticeably more
     * than the parse itself.
     *
     * Held in a static rather than the cache on purpose: a database or Redis
     * store would try to serialize the instance, and the purifier holds
     * serialisable state inconsistently enough that this would fail in
     * production rather than in the array-backed test cache that hides it.
     */
    private static ?HTMLPurifier $purifier = null;

    /**
     * @param  string|null  $html  Untrusted markup, possibly empty.
     * @return string Markup with only the allowed tags and attributes surviving.
     */
    public function handle(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        self::$purifier ??= $this->purifier();

        // Absolute URLs first, purification second, so the scheme this adds is
        // then checked by the same allow-list that checks everything else. A
        // normalisation step that ran afterwards would be the one piece of a note
        // no allow-list had ever seen.
        return self::$purifier->purify($this->absoluteUrls($html));
    }

    /**
     * Give a bare host the scheme it left out.
     *
     * Someone typing a link into a note writes `www.google.com`, because that is
     * what a link looks like. Stored as written, it is not a link at all: it is a
     * path, and the browser resolves it against the page, so "pengiriman
     * ekspedisi" on /sales/12 leads to /sales/www.google.com and 404s.
     *
     * HTMLPurifier does not catch this, and cannot: a schemeless URL is a perfectly
     * legal relative reference, so it passes its scheme allow-list unchanged. The
     * stored value is what is wrong, not the safety of it.
     *
     * Separate from `handle()` so the repair migration can apply this to notes
     * that are already stored without purifying them a second time — which the
     * class comment above warns is not lossless.
     */
    public function absoluteUrls(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;

        // libxml reads a fragment as ISO-8859-1 unless told otherwise, which turns
        // every accented character in a note into mojibake. The XML declaration is
        // the only way to say so when the fragment carries no meta tag of its own.
        //
        // The wrapper div exists so there is something to serialise back out of.
        //
        // @: libxml warns on markup it cannot fully parse, and a note is allowed
        // to be malformed. Whether the result is acceptable is the purifier's
        // decision, one line below.
        if (@$dom->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>') === false) {
            return $html;
        }

        // The wrapper is the first thing in the body, which is how it is found
        // without getElementById(): libxml's HTML parser has no DTD declaring that
        // id, so the call is unreliable here — and a note is free to begin with a
        // <div> of its own, which would make a tag lookup find the wrong one.
        $wrapper = $dom->getElementsByTagName('body')->item(0)?->firstChild;

        if (! $wrapper instanceof DOMElement) {
            return $html;
        }

        $changed = false;

        foreach (['a' => 'href', 'img' => 'src'] as $tag => $attribute) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                if (! $node->hasAttribute($attribute)) {
                    continue;
                }

                $url = $node->getAttribute($attribute);
                $absolute = $this->absolute($url);

                if ($absolute !== $url) {
                    $node->setAttribute($attribute, $absolute);
                    $changed = true;
                }
            }
        }

        // Nothing rewritten: the original string is returned rather than a
        // re-serialised one, so a note that needs no repair is not merely
        // unchanged but byte-identical.
        if (! $changed) {
            return $html;
        }

        $rebuilt = '';

        foreach ($wrapper->childNodes as $child) {
            $rebuilt .= $dom->saveHTML($child);
        }

        return $rebuilt;
    }

    /**
     * The URL as a browser will actually follow it, given the same URL with a
     * scheme already on it.
     *
     * Anything carrying a scheme is returned untouched: whether that scheme is one
     * of the allowed ones is not this method's question, and answering it here
     * would duplicate the allow-list.
     */
    private function absolute(string $url): string
    {
        $url = trim($url);

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return $url;
        }

        // Protocol-relative: it borrows the scheme of the page serving the note,
        // which for a stored record is not a decision worth preserving.
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        // A leading slash is already resolved against the site root and a fragment
        // names a place on this page. Neither is a bare host.
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }

        // A bare host: a label, at least one dot, and optionally a port and path.
        // The dot is the load-bearing part — it is what keeps the ordinary words of
        // a sentence, "bukti" or "nota", from becoming links to https://bukti.
        $host = '~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(:\d+)?([/?#].*)?$~i';

        return preg_match($host, $url) === 1 ? 'https://'.$url : $url;
    }

    /**
     * Tags a salesperson genuinely needs in a note: emphasis, lists, links,
     * headings and images. Anything with a URL is the risk surface, so `src` and
     * `href` are constrained to real schemes rather than merely permitted.
     */
    private function purifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();

        $config->set('Cache.SerializerPath', $this->serializerPath());
        $config->set('HTML.Allowed', 'a[href|title|target|rel],p,br,strong,b,em,i,u,s,'
            .'ul,ol,li,blockquote,code,pre,h1,h2,h3,h4,h5,h6,hr,'
            .'img[src|alt|title|width|height]');
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.SafeIframe', false);
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
        ]);

        // data: is deliberately absent, which is what keeps both
        // `<a href="data:...">` and `<img src="data:...">` from surviving.
        // It is not a scheme worth having in a sales note.
        $config->set('URI.DisableExternalResources', false);

        $config->set('Core.RemoveInvalidImg', true);

        return new HTMLPurifier($config);
    }

    private function serializerPath(): string
    {
        $path = storage_path('app/purifier');

        if (! is_dir($path)) {
            mkdir($path, 0o755, true);
        }

        return $path;
    }
}
