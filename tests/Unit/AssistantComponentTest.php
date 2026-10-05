<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the assistant's Alpine component against reading state it never declared.
 *
 * This exists because the first version of this component took the endpoint and
 * the CSRF token as function parameters and then read this.endpoint inside send().
 * Nothing broke in any HTTP test, because the page rendered the arguments
 * correctly and the fault was entirely in the browser's runtime state: Alpine
 * binds the returned object as the component, so a parameter that is not also a
 * property is simply undefined. The result was fetch(undefined), which the browser
 * resolved to POST /undefined, a 404, with the literal string "undefined" in the
 * X-CSRF-TOKEN header.
 *
 * A PHP test cannot run Alpine, but it can hold the invariant that caused it: every
 * this.* property the component reads must be one it declares, or an Alpine magic.
 */
class AssistantComponentTest extends TestCase
{
    /**
     * Properties Alpine injects into every component, so they are readable via
     * this.* without being declared.
     *
     * @var list<string>
     */
    private const MAGICS = ['$nextTick', '$refs', '$el', '$store', '$dispatch', '$watch'];

    /**
     * The source of the assistant component's callback: its parameter list through the
     * end of the registration call.
     *
     * The anchor is the whole registration line rather than a bare "(", because a bare
     * paren is ambiguous here: the first one after `Alpine.data(` is inside the quotes
     * of 'assistant', and the first one after the arrow is the body, not the signature.
     */
    private function componentSource(): string
    {
        $path = resource_path('js/app.js');

        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $line = strpos($source, "Alpine.data('assistant', (");

        $this->assertIsInt($line, 'app.js no longer registers `Alpine.data(\'assistant\', (...) => ...)`. '
            .'Update this test to match the new registration shape.');

        $close = strpos($source, "\n});", $line);

        $this->assertIsInt($close, 'Could not find the end of the assistant component.');

        return substr($source, $line + strlen("Alpine.data('assistant', "), $close + 2 - ($line + strlen("Alpine.data('assistant', ")));
    }

    /**
     * The properties the component declares on the returned object.
     *
     * Only keys written at the object's own indentation count. Matching any
     * identifier followed by "(" ":" "," or a newline is far too loose: it also
     * matches reads like this.endpoint (the comma) and keywords like if, which
     * would make the check pass even when the property is never declared.
     *
     * @return list<string>
     */
    private function declaredProperties(): array
    {
        // The returned object literal starts at "    return {" and its members sit
        // eight spaces in.
        preg_match_all('/^ {8}([A-Za-z_$][A-Za-z0-9_$]*)\s*[:,]/m', $this->componentSource(), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<string>
     */
    private function readProperties(): array
    {
        return array_values(array_unique($this->propertyReads()));
    }

    /**
     * Every this.* that is not a call on this. A method invocation resolves to a
     * function on the component rather than to state, so it is not a property read
     * and cannot be undefined.
     *
     * @return list<string>
     */
    private function propertyReads(): array
    {
        // Capture the whole identifier, then decide whether it is a property read
        // by inspecting whatever follows it. Doing it with a negative lookahead
        // does not work: the greedy capture gives back the last character to
        // satisfy the assertion, so this.$nextTick() matches as "$nextTic".
        preg_match_all(
            '/\bthis\.([A-Za-z_$][A-Za-z0-9_$]*)\s*(\()?/',
            $this->componentSource(),
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
        );

        $reads = [];

        foreach ($matches as [, $property, $call]) {
            if ($call !== '(') {
                $reads[] = $property;
            }
        }

        return $reads;
    }

    public function test_it_reads_nothing_it_does_not_declare(): void
    {
        $undeclared = array_diff(
            $this->readProperties(),
            $this->declaredProperties(),
            self::MAGICS,
        );

        $this->assertSame(
            [],
            array_values($undeclared),
            'The assistant component reads properties it never declares, so they are undefined at runtime. '
                .'Declare them on the returned object.'
        );
    }

    /**
     * The two properties whose absence broke the feature outright.
     */
    public function test_it_declares_the_endpoint_and_the_token(): void
    {
        $declared = $this->declaredProperties();

        $this->assertContains('endpoint', $declared, 'send() fetches this.endpoint.');
        $this->assertContains('csrfToken', $declared, 'send() sends this.csrfToken.');
    }

    /**
     * Alpine invokes a provider as callback.bind(context)(...args), so the value
     * passed in is only visible if it is also declared as a property. Destructuring
     * alone would not be enough.
     */
    /**
     * Executes the real provider from app.js against the given arguments.
     *
     * Extracting the function and running it through PHP's JS-less path is not
     * possible, so this shells out to node. It is the only assertion here that
     * actually reproduces the browser's behaviour, and it is what caught the bug that
     * the source-level checks above were blind to: an earlier version of the component
     * destructured a single object while the template passed two positional
     * arguments, so endpoint resolved to undefined at runtime while every textual
     * check still passed.
     *
     * @param  array<int, mixed>  $args
     * @return array<string, mixed>
     */
    private function runProvider(array $args): array
    {
        $script = sprintf(
            'const provider = new Function("return " + %s)();'
            .'const state = provider.call({}, ...%s);'
            .'console.log(JSON.stringify({endpoint: state.endpoint ?? null, csrfLength: (state.csrfToken ?? "").length}));',
            json_encode($this->componentSource()),
            json_encode($args),
        );

        $file = tempnam(sys_get_temp_dir(), 'assistant').'.cjs';
        file_put_contents($file, $script);

        try {
            $output = shell_exec('node '.escapeshellarg($file).' 2>&1');
        } finally {
            @unlink($file);
        }

        $decoded = json_decode(trim((string) $output), true);

        $this->assertIsArray($decoded, 'Could not evaluate the assistant provider with node: '.trim((string) $output));

        return $decoded;
    }

    public function test_it_receives_the_two_arguments_the_template_passes(): void
    {
        $state = $this->runProvider(['https://example.test/assistant', 'a-token-value']);

        $this->assertSame('https://example.test/assistant', $state['endpoint']);
        $this->assertSame(strlen('a-token-value'), $state['csrfLength']);
    }

    public function test_it_also_accepts_a_single_object_argument(): void
    {
        $state = $this->runProvider([
            ['endpoint' => 'https://example.test/assistant', 'csrfToken' => 'a-token-value'],
        ]);

        $this->assertSame('https://example.test/assistant', $state['endpoint']);
        $this->assertSame(strlen('a-token-value'), $state['csrfLength']);
    }

    /**
     * The regression that shipped: the arguments arrived but were discarded, so
     * fetch() posted to "/undefined".
     */
    public function test_the_endpoint_is_never_undefined(): void
    {
        $this->assertNotNull($this->runProvider(['https://example.test/assistant', 'tok'])['endpoint']);
    }

    public function test_the_template_passes_both_arguments(): void
    {
        $template = file_get_contents(resource_path('views/components/assistant.blade.php'));

        $this->assertStringContainsString('x-data="assistant(', $template);
        $this->assertStringContainsString("route('assistant.store')", $template);
        $this->assertStringContainsString('csrf_token()', $template);
    }
}
