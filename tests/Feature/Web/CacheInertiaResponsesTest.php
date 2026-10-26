<?php

namespace Tests\Feature\Web;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CacheInertiaResponsesTest extends TestCase
{
    use RefreshDatabase;

    private function inertiaVersion(): string
    {
        return hash_file('xxh128', public_path('build/manifest.json'));
    }

    public function test_inertia_response_carries_an_etag(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Product::factory()->create(['name' => 'Toko Kopi']);

        $response = $this->withHeaders([
            'X-Inertia' => true,
            'X-Inertia-Version' => $this->inertiaVersion(),
        ])->get('/products');

        $response->assertOk();
        $response->assertHeader('ETag');
    }

    public function test_repeated_inertia_visit_receives_304(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Product::factory()->create(['name' => 'Toko Kopi']);

        $headers = [
            'X-Inertia' => true,
            'X-Inertia-Version' => $this->inertiaVersion(),
        ];

        $first = $this->withHeaders($headers)->get('/products');
        $first->assertOk();
        $etag = $first->headers->get('ETag');

        $second = $this->withHeaders([
            ...$headers,
            'If-None-Match' => $etag,
        ])->get('/products');

        $second->assertStatus(304);
    }

    public function test_non_inertia_request_does_not_get_etag(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Product::factory()->create(['name' => 'Toko Kopi']);

        $response = $this->get('/products');

        $response->assertOk();
        $response->assertHeaderMissing('ETag');
    }

    public function test_non_ok_response_does_not_get_etag(): void
    {
        $response = $this->withHeaders(['X-Inertia' => true])
            ->get('/products');

        $response->assertRedirect(route('login'));
        $response->assertHeaderMissing('ETag');
    }
}
