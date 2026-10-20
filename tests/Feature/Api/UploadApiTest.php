<?php

namespace Tests\Feature\Api;

use App\Actions\StoreUploadedImage;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * The failure this action exists to refuse, and what it says about it.
     *
     * The storage client's error is the only thing that knows why an upload
     * failed, and it used to be discarded three times over: the adapter caught it,
     * 'throw' said not to raise it, and 'report' said not to log it. The caller
     * was told the upload succeeded, the url went into the sale's note, and the
     * only symptom was a broken image with nothing anywhere to explain it.
     *
     * The disk is mocked rather than faked on purpose. `Storage::fake()` installs
     * an in-memory adapter that accepts every write, and a local disk pointed at
     * an unwritable root raises a different failure entirely. Mocking is the only
     * way to produce the storage client's own error.
     */
    public function test_a_failed_upload_says_what_to_check(): void
    {
        $this->expectException(RuntimeException::class);

        // And the message names what to check. An administrator looking at a
        // storage backend configured once has three suspects and no log line, so
        // the sentence is the only thing that will tell them where to look.
        $this->expectExceptionMessage('Periksa AWS_BUCKET');

        $this->uploadFailingWith('NoSuchBucket');
    }

    /**
     * Each storage error means something different, and the sentence says which.
     *
     * The raw message names the code and leaves the reader to work out what to do
     * about it, which is the whole reason the codes are mapped.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function storageErrors(): array
    {
        return [
            'a bucket that is not there' => ['NoSuchBucket', 'Bucket tidak ada di project ini'],
            'a rejected key' => ['SignatureDoesNotMatch', 'Kredensial ditolak'],
            'an unknown key' => ['InvalidAccessKeyId', 'Access key tidak dikenal'],
            'a refused bucket' => ['AccessDenied', 'Akses ke bucket ditolak'],
            'a file too large' => ['EntityTooLarge', 'Gambar melebihi batas'],
        ];
    }

    #[DataProvider('storageErrors')]
    public function test_the_storage_code_becomes_a_sentence(string $code, string $expected): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expected);

        $this->uploadFailingWith($code);
    }

    /**
     * The endpoint tells the caller what went wrong.
     *
     * Left to the exception handler it would answer "Server Error" — APP_DEBUG is
     * off in production — and the person watching the editor would learn nothing.
     * The editor shows the message it is given, so the sentence has to arrive
     * here rather than in a log line nobody opens.
     */
    public function test_the_endpoint_returns_the_reason_rather_than_a_blank_error(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->mockFailingDisk('NoSuchBucket');

        $response = $this->post('/api/uploads', ['image' => $this->png()], ['Accept' => 'application/json']);

        $response->assertStatus(500);

        $this->assertStringContainsString('Bucket tidak ada di project ini', $response->json('message') ?? '');

        // And no half-built record: a path was never invented for a file that is
        // not there. That invented path is what got written into the note before.
        $this->assertStringNotContainsString('"path"', $response->getContent());
    }

    /**
     * The upload fails with the storage client's own error.
     *
     * The error is raised the way a storage client raises it: as the previous
     * exception under the filesystem wrapper, which is how it arrives in practice
     * and how the code has to be found.
     */
    private function uploadFailingWith(string $code): void
    {
        $this->mockFailingDisk($code);

        (new StoreUploadedImage)->handle($this->png());
    }

    private function mockFailingDisk(string $code): void
    {
        config()->set('images.disk', 'unreachable');

        config()->set('filesystems.disks.unreachable', ['driver' => 'local', 'root' => '/tmp']);

        $clientError = new class($code) extends RuntimeException
        {
            public function __construct(private readonly string $awsCode)
            {
                parent::__construct('Error executing "PutObject" with AWS error code '.$awsCode);
            }

            public function getAwsErrorCode(): string
            {
                return $this->awsCode;
            }
        };

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andThrow(
            UnableToWriteFile::atLocation('uploads/x.png', $clientError->getMessage(), $clientError)
        );

        Storage::shouldReceive('disk')->with('unreachable')->andReturn($disk);
    }
}
