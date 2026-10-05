<?php

namespace Tests\Feature;

use App\Ai\Agents\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

/**
 * The bubble's only endpoint. Two things matter: a guest cannot spend money on the
 * metered API, and a provider failure degrades instead of erroring.
 */
class AssistantEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fakes the assistant, returning the same reply for every prompt.
     *
     * The reply is a plain string, not an array. FakeTextGateway turns an array
     * into a StructuredTextResponse, which is the wrong shape for an agent with no
     * output schema, and the endpoint then receives JSON where it expects text.
     *
     * A closure rather than a list, because the gateway indexes a list by request
     * number: a one-element list is exhausted by the second request and it then
     * starts generating data from the schema instead.
     */
    private function fakeAssistant(string $reply = 'Halo, ada yang bisa dibantu?'): void
    {
        Ai::fakeAgent(Assistant::class, fn (): string => $reply);
    }

    public function test_a_guest_cannot_use_it(): void
    {
        $this->fakeAssistant();

        // A JSON request from a guest is answered with 401, not a redirect to the
        // login form, because shouldRenderJsonWhen matches this Accept header.
        $this->postJson('/assistant', ['message' => 'halo'])->assertUnauthorized();

        Ai::assertAgentNeverPrompted(Assistant::class);
    }

    public function test_it_returns_the_reply(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeAssistant('Halo dari asisten.');

        $response = $this->postJson('/assistant', ['message' => 'halo']);

        $response->assertOk();
        $this->assertSame('Halo dari asisten.', $response->json('reply'));
    }

    public function test_it_sends_the_message_to_the_model(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeAssistant();

        $this->postJson('/assistant', ['message' => 'apa kabar?'])->assertOk();

        Ai::assertAgentWasPrompted(
            Assistant::class,
            fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'apa kabar?'),
        );
    }

    public function test_it_remembers_the_conversation_per_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->fakeAssistant();

        $this->postJson('/assistant', ['message' => 'pertanyaan pertama'])->assertOk();
        $this->postJson('/assistant', ['message' => 'pertanyaan kedua'])->assertOk();

        // Two turns on one account means two stored conversations.
        $this->assertDatabaseCount('agent_conversations', 2);
        $this->assertDatabaseCount('agent_conversation_messages', 4);

        // A second user must not inherit the first one's history.
        $this->actingAs(User::factory()->create());
        $this->postJson('/assistant', ['message' => 'halo dari akun lain'])->assertOk();

        $this->assertDatabaseCount('agent_conversations', 3);
    }

    public function test_it_requires_a_message(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/assistant', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_it_rejects_an_overlong_message(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/assistant', ['message' => str_repeat('a', 501)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_a_provider_failure_degrades_instead_of_erroring(): void
    {
        $this->actingAs(User::factory()->create());

        // No key, no quota, or a timeout all land here, and none of them are
        // something the person asking can fix, so it must not surface as a 500.
        Ai::fakeAgent(Assistant::class, function (): never {
            throw new AiException('quota exceeded');
        });

        $this->postJson('/assistant', ['message' => 'halo'])
            ->assertStatus(503)
            ->assertJsonFragment(['error' => 'Maaf, layanan AI sedang tidak tersedia. Coba lagi sebentar lagi.']);
    }

    /**
     * The message the user sees is deliberately generic, which means a
     * misconfiguration and a genuine outage look identical from the browser. That
     * is only acceptable if the real cause is recorded somewhere, and it was not:
     * the first version of this test threw an exception class the SDK does not
     * have, so report() logged a class-not-found instead of the failure and the
     * test still passed.
     */
    public function test_a_provider_failure_is_logged(): void
    {
        $this->actingAs(User::factory()->create());

        Ai::fakeAgent(Assistant::class, function (): never {
            throw new AiException('quota exceeded');
        });

        $logged = '';
        Log::listen(function ($message) use (&$logged): void {
            $logged .= $message->message;
        });

        $this->postJson('/assistant', ['message' => 'halo'])->assertStatus(503);

        $this->assertStringContainsString(
            'quota exceeded',
            $logged,
            'The underlying cause must reach the log, or a configuration mistake is indistinguishable from an outage.'
        );
    }

    public function test_it_is_throttled_per_user(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeAssistant();

        foreach (range(1, 20) as $ignored) {
            $this->postJson('/assistant', ['message' => 'halo'])->assertOk();
        }

        $this->postJson('/assistant', ['message' => 'halo'])->assertStatus(429);
    }

    public function test_the_limit_does_not_leak_between_users(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeAssistant();

        foreach (range(1, 20) as $ignored) {
            $this->postJson('/assistant', ['message' => 'halo'])->assertOk();
        }

        // A different account starts with a full allowance of its own, which is
        // the whole reason the limiter is keyed on the user and not the address.
        $this->actingAs(User::factory()->create())
            ->postJson('/assistant', ['message' => 'halo'])
            ->assertOk();
    }
}
