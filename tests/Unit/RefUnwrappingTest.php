<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A ref is a template concept. Reading one in a script means writing `.value`.
 *
 * Vue unwraps a `ref()` or a `computed()` when the template renders it, and does
 * not unwrap it anywhere else. In `<script setup>` the two are different things:
 * `changing` in `{{ changing ? 'a' : 'b' }}` is the value, and `changing` in a
 * function is the ref object — which is truthy whatever it holds.
 *
 * That failure is silent and it is nasty, because the two halves disagree. A form
 * that decides whether it is adding or changing could render its button from the
 * unwrapped value and branch on the wrapped one, so the page said "Tambah" while
 * every submission went out as the amend. Reference/Form.vue did exactly this:
 * `if (changing)` in `submit()` was always true, so adding a tag, a city or a
 * salesperson sent a PATCH to an `updateUrl` the create page never sends. Inertia
 * resolves that to the current URL, the route table reads `create` as a row id, and
 * the desk was answered 404 — from the form, with the right label on the button.
 *
 * A browser test catches it properly, but there is no JavaScript test runner in
 * this project and adding one is a larger decision than this check. Scanning the
 * source costs nothing and fails loudly, which is the same bargain
 * PageLayoutTest makes and the same reason it gives.
 *
 * The scan covers components as well as pages: the mistake is a JavaScript one and
 * has no reason to stop at the pages directory.
 */
class RefUnwrappingTest extends TestCase
{
    public function test_no_component_reads_a_ref_as_a_value_inside_its_script(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('js')) as $file) {
            if ($file->getExtension() !== 'vue') {
                continue;
            }

            foreach ($this->misreadsIn($file->getContents()) as $line) {
                $offenders[] = str_replace(resource_path('js/'), '', $file->getPathname()).":{$line} {$line}";
            }
        }

        $this->assertNotEmpty(
            File::allFiles(resource_path('js')),
            'No components found; the walk is wrong, not the codebase.'
        );

        $this->assertSame(
            [],
            $offenders,
            "A ref is truthy whatever it holds, and Vue only unwraps one in the template:\n"
                ."  if (editing) in a function is always true\n"
                .'Write `.value` there, or read the underlying value — `props.row` — as Reference/Form.vue now does.'
        );
    }

    /**
     * The line numbers in a component's `<script setup>` block where a ref is read
     * as though it were its own value.
     *
     * @return list<int>
     */
    private function misreadsIn(string $source): array
    {
        if (preg_match('/<script setup>(.*?)<\/script>/s', $source, $matches) !== 1) {
            return [];
        }

        // Comments go first. A component explaining this exact mistake in a
        // docblock above the line that used to make it is the most likely thing in
        // the codebase to contain the text the scan looks for, and prose about the
        // mistake is not the mistake.
        $script = $this->withoutComments($matches[1]);

        preg_match_all('/\bconst\s+([A-Za-z_$][\w$]*)\s*=\s*(?:computed|ref)\s*\(/', $script, $declarations);

        $lines = [];

        foreach (array_unique($declarations[1]) as $name) {
            foreach ($this->patternsFor($name) as $pattern) {
                if (preg_match_all($pattern, $script, $hits, PREG_OFFSET_CAPTURE) === 0) {
                    continue;
                }

                foreach ($hits[0] as [$text, $offset]) {
                    $lines[substr_count(substr($script, 0, $offset), "\n") + 1] = true;
                    unset($text);
                }
            }
        }

        $found = array_keys($lines);
        sort($found);

        return $found;
    }

    /**
     * The script with its comments blanked out, and its line numbers intact.
     *
     * Removing a block comment outright would collapse the lines inside it and
     * renumber everything below, so a removed run is replaced with the same number
     * of newlines and the reported line still points at the line it points at.
     *
     * The lookbehind on the line-comment pass keeps the `//` in a URL from opening
     * a comment: every URL in this codebase is preceded by a colon, and a comment
     * swallowed from there on would be a line the scan could no longer read.
     */
    private function withoutComments(string $script): string
    {
        $blanked = preg_replace_callback(
            '#/\*.*?\*/#s',
            fn (array $comment): string => str_repeat("\n", substr_count($comment[0], "\n")),
            $script,
        );

        return (string) preg_replace('#(?<!:)//[^\n]*#', '', $blanked);
    }

    /**
     * The positions where a bare identifier is read as a value rather than as the
     * ref wrapping it.
     *
     * Every pattern refuses a name followed by `.`, `?.` or another word character,
     * so `editing.value`, `editing?.value` and `editValue` are left alone. That is
     * what separates a real finding from the ordinary `.value` every other read in
     * this codebase already has.
     *
     * @return list<string>
     */
    private function patternsFor(string $name): array
    {
        $n = preg_quote($name, '/');

        // Not preceded by a dot, and not followed by one either.
        $before = '(?<![\w.$])';
        $after = '(?![\w.?$])';

        return [
            // if (open) / if (!open)
            "/\bif\s*\(\s*!?\s*{$n}{$after}/",
            // while (open)
            "/\bwhile\s*\(\s*!?\s*{$n}{$after}/",
            // open && x / x && open
            "/{$before}{$n}{$after}\s*&&/",
            "/&&\s*!?\s*{$n}{$after}/",
            // open || x / x || open
            "/{$before}{$n}{$after}\s*\|\|/",
            "/\|\|\s*!?\s*{$n}{$after}/",
            // open ? a : b, the ternary's condition
            "/{$before}{$n}{$after}\s*\?/",
            // !open anywhere at all, which is the same mistake in another costume
            "/{$before}!\s*{$n}{$after}/",
        ];
    }
}
