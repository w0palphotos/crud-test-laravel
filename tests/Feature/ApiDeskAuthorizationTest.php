<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The administrator boundary on the token surface.
 *
 * Separate from DeskAuthorizationTest because the answer is different: a bearer
 * token has no browser to redirect, so the only meaningful refusal is 403 with a
 * body. Sharing one file would mean one of the two surfaces had its status code
 * quietly wrong.
 */
class ApiDeskAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function deskEndpoints(): array
    {
        return [
            'list products' => ['get', '/api/products'],
            'create product' => ['post', '/api/products'],
        ];
    }

    #[DataProvider('deskEndpoints')]
    public function test_an_ordinary_token_is_refused_the_catalogue(string $method, string $path): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->json($method, $path)->assertForbidden();
    }

    public function test_an_administrator_token_reaches_the_catalogue(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/products')->assertOk();
    }

    /**
     * A token is just a token: it carries no role of its own, so the account's
     * flag is the only thing that decides. Proved by minting a real token from an
     * ordinary account rather than by asserting on the acting-as helper, which
     * bypasses the guard entirely.
     */
    public function test_a_minted_token_from_an_ordinary_account_is_refused(): void
    {
        $token = User::factory()->create()->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/products')
            ->assertForbidden();
    }

    /**
     * Self-service stays open to every account, so an ordinary caller is not
     * locked out of the API entirely.
     */
    public function test_an_ordinary_token_still_manages_its_own_account(): void
    {
        Sanctum::actingAs(User::factory()->create(['name' => 'Ordinary']));

        $this->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.name', 'Ordinary');

        $this->patchJson('/api/account', ['name' => 'Renamed'])->assertOk();
    }

    /**
     * The flag is visible to the account's own owner and is not a write target.
     *
     * Read-only is the point: an ordinary caller learning their own kind is fine,
     * and one setting the flag through the account endpoint would be the whole
     * boundary undone.
     */
    public function test_the_account_kind_is_readable_and_cannot_be_assigned(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/account')->assertOk()->assertJsonPath('data.is_admin', false);

        $this->patchJson('/api/account', ['is_admin' => true])->assertOk();

        $this->assertFalse($user->fresh()->is_admin);
    }

    /**
     * Creating a product is refused too, not only reading it.
     *
     * Asserted with a valid body so a 403 cannot be mistaken for the 422 an
     * invalid one would produce — the point is which check ran first.
     */
    public function test_an_ordinary_token_cannot_create_a_product(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/products', [
            'name' => 'Cast Iron Skillet',
            'price' => '49.99',
            'stock' => 5,
        ])->assertForbidden();

        $this->assertSame(0, Product::count());
    }
}
