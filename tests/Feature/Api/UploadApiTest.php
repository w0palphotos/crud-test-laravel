<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API's image upload.
 *
 * Tested against the reason the endpoint exists rather than against the storage
 * limits a second time over: a product is required to have a gallery, a gallery is
 * a list of paths into the image disk, and without somewhere to put the file a
 * token holder could not satisfy that rule at all. So the test that matters is
 * the round trip — upload, then create the product with what came back.
 *
 * The limits themselves are StoreUploadRequest's, and they are proved once on the
 * browser door in UploadTest. Both doors run the same request, so proving them
 * twice would only prove that the test file still passes.
 */
class UploadApiTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'images';

    /**
     * A genuine PNG, as bytes.
     *
     * Written by hand rather than with UploadedFile::fake(), for the reason
     * UploadTest gives: the fake reports whatever MIME type it was told and never
     * looks at the content.
     */
    private const PNG_BYTES = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('images.disk', self::DISK);
        Storage::fake(self::DISK);
    }

    private function png(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'api-upload-test');

        file_put_contents($path, base64_decode(self::PNG_BYTES));

        return new UploadedFile($path, 'keyboard.png', 'image/png', null, true);
    }

    public function test_an_uploaded_image_can_be_used_as_a_products_gallery(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $upload = $this->post('/api/uploads', ['image' => $this->png()], ['Accept' => 'application/json']);

        $upload->assertCreated();

        $path = $upload->json('path');

        Storage::disk(self::DISK)->assertExists($path);

        // The round trip, and the reason for the endpoint: the path this door
        // returns is a path the other door accepts.
        $this->postJson('/api/products', [
            'name' => 'Mechanical Keyboard',
            'price' => 129.99,
            'stock' => 42,
            'images' => [['path' => $path, 'alt_text' => 'Tampak atas']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.images.0.path', $path);
    }

    public function test_it_returns_a_name_of_its_own_and_not_the_one_that_was_sent(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $upload = $this->post('/api/uploads', ['image' => $this->png()], ['Accept' => 'application/json']);

        // A client-supplied filename is how one upload overwrites another's, and
        // how `../../` reaches outside the directory. Both doors generate the name.
        $this->assertNotSame('keyboard.png', $upload->json('name'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $upload->json('name'));
    }

    public function test_it_refuses_a_file_that_is_not_an_accepted_image(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->post('/api/uploads', [
            'image' => UploadedFile::fake()->create('payload.svg', 8, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame([], Storage::disk(self::DISK)->allFiles());
    }

    public function test_a_guest_cannot_upload(): void
    {
        $this->postJson('/api/uploads')->assertUnauthorized();
    }
}
