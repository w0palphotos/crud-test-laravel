<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_bubble_is_shown_on_a_signed_in_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/products')
            ->assertOk()
            ->assertSee('Asisten')
            ->assertSee('id="assistant-panel"', escape: false)
            ->assertSee('assistant-panel', escape: false)
            // @js() escapes the slashes in the URL, so match the endpoint path
            // rather than the full escaped URL.
            ->assertSee(parse_url(route('assistant.store'), PHP_URL_PATH), escape: false);
    }

    public function test_the_bubble_is_hidden_from_guests(): void
    {
        // The endpoint calls a metered API, so the launcher must not appear where
        // nobody could use it.
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Buka asisten');

        $this->get('/register')->assertDontSee('Buka asisten');
    }
}
