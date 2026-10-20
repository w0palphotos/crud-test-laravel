<?php

namespace Tests\Feature\Api;

use App\Actions\StoreUploadedImage;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
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

    /**
     * The endpoint reports a failed write rather than a result.
     *
     * Driven through the endpoint rather than the action so it also proves the
     * failure reaches the caller, which is the point: the reply used to be 201
     * with a path in it, and that path went straight into a sale's note.
     *
     * Not the same failure the two tests below cover — a local disk cannot be
     * created as a directory and raises instead of returning false — but it is a
     * failure a real disk can produce, and the guarantee is the same either way:
     * no write, no path, no success.
     */
    public function test_a_write_that_did_not_happen_is_reported_rather_than_swallowed(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $blocker = tempnam(sys_get_temp_dir(), 'not-a-directory');

        config()->set('images.disk', 'unwritable');
        config()->set('filesystems.disks.unwritable', [
            'driver' => 'local',
            'root' => $blocker.'/uploads',
            'throw' => false,
        ]);

        $response = $this->post('/api/uploads', ['image' => $this->png()], ['Accept' => 'application/json']);

        $response->assertStatus(500);

        // No half-built record: a path was never invented for a file that is not
        // there. That invented path is what got written into the note before.
        $this->assertStringNotContainsString('"path"', $response->getContent());
    }

    /**
     * The failure this action exists to refuse.
     *
     * A rejected credential or an unreachable S3 endpoint makes storeAs() return
     * false rather than raise, because the disk is configured not to throw. The
     * endpoint used to build a URL from that false and report success: the upload
     * looked fine, the URL went into the sale's note, and the only symptom was a
     * broken image with nothing in any log to explain it.
     *
     * The disk is mocked rather than faked on purpose. `Storage::fake()` installs an
     * in-memory adapter that accepts every write, and a local disk pointed at an
     * unwritable root raises UnableToCreateDirectory instead of returning false —
     * neither is what an S3 client does. Mocking is the only way to produce the
     * return value this is about.
     */
    public function test_a_failed_upload_says_what_to_check(): void
    {
        config()->set('images.disk', 'unreachable');

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);

        Storage::shouldReceive('disk')->with('unreachable')->andReturn($disk);

        $this->expectException(RuntimeException::class);

        // And the message names what to check. An administrator looking at a
        // storage backend configured once has three suspects and no log line, so
        // the sentence is the only thing that will tell them where to look.
        $this->expectExceptionMessage('Periksa kredensial Storage');

        (new StoreUploadedImage)->handle($this->png());
    }

    /**
     * And no path is invented for a file that is not there.
     *
     * The invented path is what got written into the note before, which is why
     * this is worth asserting separately from the exception: raising stops the
     * request, and returning is what wrote the URL.
     */
    public function test_a_failed_upload_never_reports_a_path(): void
    {
        config()->set('images.disk', 'unreachable');

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andReturn(false);

        Storage::shouldReceive('disk')->with('unreachable')->andReturn($disk);

        try {
            (new StoreUploadedImage)->handle($this->png());
        } catch (RuntimeException) {
            // Nothing was returned, so there is no array holding a path at all.
            $this->assertTrue(true);

            return;
        }

        $this->fail('A failed upload must not return a result.');
    }
}
