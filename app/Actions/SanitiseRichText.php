<?php

namespace App\Actions;

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
     * @param  string  $html  Untrusted markup, possibly empty.
     * @return string Markup with only the allowed tags and attributes surviving.
     */
    public function handle(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        self::$purifier ??= $this->purifier();

        return self::$purifier->purify($html);
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
