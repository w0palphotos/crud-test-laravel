<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The assistant bubble's wiring.
 *
 * The panel is a Vue component inside the persistent layout, so there is no
 * server-rendered bubble markup to assert on any more. What remains observable
 * from PHP is the wiring itself: which URL the component is handed.
 *
 * The endpoint's own behaviour — authentication, throttling, conversation
 * memory, provider failure — is covered by AssistantEndpointTest.
 */
class AssistantWidgetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * AppLayout passes `:endpoint="routes.assistant"` to the bubble. This pins
     * that the key it reads holds the real assistant route, which is the half of
     * that wiring PHP can see. The other half, that the component asks for that
     * key by name, is pinned by InertiaRoutePropsTest.
     */
    public function test_a_signed_in_page_shares_the_assistant_endpoint(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('routes.assistant', route('assistant.store')));
    }

    /**
     * A guest never reaches a page carrying the layout, so the bubble is not
     * rendered for them. The endpoint calls a metered API, so this is about
     * spending money, not only about tidiness.
     */
    public function test_a_guest_is_never_handed_a_page_with_the_layout(): void
    {
        $this->get('/products')->assertRedirect(route('login'));
        $this->get('/account')->assertRedirect(route('login'));
    }

    /**
     * The login page carries no layout and no shared route map, because it is
     * rendered as Blade.
     *
     * Only the login page now. The register form is also Blade, but it belongs to
     * an administrator rather than to a guest, so this suite reaches it as one.
     */
    public function test_the_bubble_is_absent_from_the_guest_pages(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('routes', escape: false);

        $this->actingAs(User::factory()->admin()->create());

        $this->get('/register')->assertOk()->assertDontSee('routes', escape: false);
    }
}
