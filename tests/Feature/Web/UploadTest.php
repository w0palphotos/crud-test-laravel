<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The image upload endpoint.
 *
 * An upload endpoint is a trust boundary: it accepts a file chosen by whoever is
 * signed in, writes it to storage, and hands back a URL that other users will be
 * shown. Every test here corresponds to a way that can go wrong.
 */
class UploadTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'images';

    protected function setUp(): void
    {
        parent::setUp();

        // Point the application at a faked disk *and* point config at it too.
        //
        // The controller reads config('images.disk'), not the literal 'images'.
        // Faking a disk the application never writes to would make every
        // assertion about the file — including "nothing was written" — pass
        // against an empty disk that was never involved. That is how a whole
        // suite of rejection tests can pass while proving nothing.
        config()->set('images.disk', self::DISK);
        Storage::fake(self::DISK);
    }

    /**
     * A genuine PNG, as bytes.
     *
     * Written by hand rather than with UploadedFile::fake(), because the fake
     * reports whatever MIME type it was told and never looks at the content. A
     * test built on it would pass whether or not the server actually sniffed
     * the bytes, which is the exact thing this endpoint has to get right.
     *
     * This environment has no GD extension, so the framework's own image()
     * factory cannot run. The bytes below are a 1x1 PNG, which every sniffer
     * recognises from its signature.
     */
    private const PNG_BYTES = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * A real uploaded file built from real bytes.
     *
     * `new UploadedFile(..., test: true)` leaves the client-supplied MIME type
     * reported as such while getMimeType() falls back to sniffing the content,
     * which is exactly the behaviour the production request has.
     *
     * @param  string  $contents  the bytes actually stored
     * @param  string  $name  the name the client claims
     * @param  string  $clientMime  the type the client declares
     */
    private function upload(string $contents, string $name, string $clientMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload-test');

        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $clientMime, null, true);
    }

    /**
     * A valid PNG, padded to a given size so the byte cap can be exercised.
     *
     * Padding goes after the PNG's end marker, so the signature a sniffer reads
     * is untouched and the file is still a PNG of whatever length is asked for.
     */
    private function png(int $kilobytes = 1): UploadedFile
    {
        $contents = base64_decode(self::PNG_BYTES);
        $target = $kilobytes * 1024;

        if (strlen($contents) < $target) {
            $contents .= str_repeat("\0", $target - strlen($contents));
        }

        return $this->upload($contents, 'kitchen-sink.png', 'image/png');
    }

    public function test_a_guest_cannot_upload(): void
    {
        $this->postJson('/admin/uploads', ['image' => $this->png()])
            ->assertUnauthorized();

        Storage::disk(self::DISK)->assertEmpty();
    }

    public function test_a_signed_in_user_can_upload(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->post('/admin/uploads', ['image' => $this->png()]);

        $response->assertOk()->assertJsonStructure(['path', 'url', 'name']);
    }

    /**
     * A failure the browser sees is also a failure the server keeps.
     *
     * The endpoint answers with the reason, and that is the half the person can
     * read. The other half is the log: without it, the deployment's own
     * environment and the stack trace live nowhere, and the symptom can only be
     * matched against a guess.
     */
    public function test_a_failed_upload_is_reported_to_the_log(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        config()->set('images.disk', 'unreachable');
        config()->set('filesystems.disks.unreachable', [
            'driver' => 's3',
            'key' => '',
            'secret' => '',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        Log::shouldReceive('error')->once();

        $this->post('/admin/uploads', ['image' => $this->png()], ['Accept' => 'application/json'])
            ->assertStatus(500);
    }

    /**
     * The refusal names the configuration the application resolved.
     *
     * "A variable is missing" says what went wrong and nothing about what the
     * application is actually talking to — and those are the facts that tell a
     * wrong value apart from a value that never arrived. A host name and a bucket
     * name are not secrets, and they belong in the log line.
     */
    public function test_a_refusal_carries_the_resolved_configuration(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        config()->set('images.disk', 'unreachable');
        config()->set('filesystems.disks.unreachable', [
            'driver' => 's3',
            'key' => '',
            'secret' => '',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        $response = $this->post('/admin/uploads', ['image' => $this->png()], ['Accept' => 'application/json']);

        $message = $response->json('message') ?? '';

        $this->assertStringContainsString('AWS_ACCESS_KEY_ID', $message);
        $this->assertStringContainsString('endpoint: https://example.supabase.co/storage/v1/s3', $message);
        $this->assertStringContainsString('bucket: gambar', $message);
        $this->assertStringContainsString('region: ap-southeast-1', $message);
    }

    /**
     * The upload sends an ACL the bucket will accept.
     *
     * A bucket with owner-enforced ownership — the default everywhere since 2023,
     * and Supabase's too — accepts only the bucket-owner-full-control canned ACL
     * or none at all. "private" and "public-read" are both refused, and the
     * refusal is what a bucket with that setting gives.
     *
     * The file adapter sends one of those two whatever the disk is configured to,
     * so the value has to be set on the call itself. It says who owns the object,
     * not who may read it, so it grants nothing the public bucket was not already
     * granting.
     */
    public function test_the_upload_sends_an_acl_a_bucket_will_accept(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $sent = null;

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andReturnUsing(function (string $path, $file, string $name, array $options = []) use (&$sent): string {
            $sent = $options['params']['ACL'] ?? null;

            return 'uploads/'.$name;
        });
        $disk->shouldReceive('url')->andReturn('/storage/uploads/x.jpg');

        Storage::shouldReceive('disk')->with(self::DISK)->andReturn($disk);

        $this->post('/admin/uploads', ['image' => $this->png()])->assertOk();

        $this->assertSame('bucket-owner-full-control', $sent);
    }

    /**
     * A failed upload says why, in the place the person is looking.
     *
     * The editor shows the message it is given, so the sentence has to arrive in
     * the reply. Left to the exception handler it would answer "Server Error" —
     * APP_DEBUG is off in production — and the person configuring the bucket would
     * learn nothing from a page that says only that something went wrong.
     */
    public function test_a_failed_upload_says_why_rather_than_only_that_it_failed(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $clientError = new class extends RuntimeException
        {
            public function __construct()
            {
                parent::__construct('Error executing "PutObject" with AWS error code NoSuchBucket');
            }

            public function getAwsErrorCode(): string
            {
                return 'NoSuchBucket';
            }
        };

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andThrow(
            UnableToWriteFile::atLocation('uploads/x.png', $clientError->getMessage(), $clientError)
        );

        Storage::shouldReceive('disk')->with(self::DISK)->andReturn($disk);

        $response = $this->post('/admin/uploads', ['image' => $this->png()], ['Accept' => 'application/json']);

        $response->assertStatus(500);

        $this->assertStringContainsString('Bucket tidak ada di project ini', $response->json('message') ?? '');

        // And no path for a file that is not there: that path is what got written
        // into a sale's note before, and it is what made the broken image.
        $this->assertStringNotContainsString('"path"', $response->getContent());
    }

    /**
     * The stored filename must not be the client's.
     *
     * A caller-supplied name has three separate edges: `../` writes outside the
     * directory, a `.php` extension becomes executable wherever the server runs
     * PHP, and a predictable name lets one user overwrite another's file by
     * guessing it.
     */
    public function test_the_stored_name_is_generated_and_the_clients_is_discarded(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->post('/admin/uploads', [
            'image' => $this->png(4),
        ]);

        $response->assertOk();

        $name = $response->json('name');
        $path = $response->json('path');

        $this->assertStringNotContainsString('not-really', $name);
        $this->assertStringEndsWith('.png', $name);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.png$/',
            $name,
            'The name must be a UUID plus a generated extension.',
        );

        $this->assertStringStartsWith('uploads/', $path);
        $this->assertStringNotContainsString('..', $path);
    }

    /**
     * A traversal attempt in the filename has nowhere to aim: the directory is
     * fixed and the name is a UUID.
     */
    public function test_a_traversal_filename_cannot_escape_the_directory(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $response = $this->post('/admin/uploads', [
            'image' => $this->upload(base64_decode(self::PNG_BYTES), '../../config/app.png', 'image/png'),
        ]);

        $response->assertOk();

        $path = $response->json('path');

        $this->assertStringStartsWith('uploads/', $path);
        $this->assertStringNotContainsString('..', $path);

        Storage::disk(self::DISK)->assertExists($path);
    }

    /**
     * The extension comes from what the server read, not from what the client
     * called the file. A script renamed to .png is rejected on its bytes.
     */
    public function test_a_non_image_is_rejected_even_when_renamed_to_png(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/admin/uploads', [
            'image' => $this->upload('<?php echo shell_exec($_GET["c"]); ?>', 'payload.png', 'image/png'),
        ])
            ->assertSessionHasErrors('image');

        Storage::disk(self::DISK)->assertEmpty();
    }

    public function test_a_php_script_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/admin/uploads', [
            'image' => $this->upload('<?php echo 1; ?>', 'shell.php', 'application/x-php'),
        ])->assertSessionHasErrors('image');

        Storage::disk(self::DISK)->assertEmpty();
    }

    /**
     * SVG is an image by MIME type and a document that can carry script, so it is
     * not on the allow list at all.
     */
    public function test_an_svg_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/admin/uploads', [
            'image' => $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg', 'image/svg+xml'),
        ])->assertSessionHasErrors('image');

        Storage::disk(self::DISK)->assertEmpty();
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $limit = (int) config('images.max_kilobytes');

        $this->post('/admin/uploads', [
            'image' => $this->png($limit + 64),
        ])->assertSessionHasErrors('image');

        Storage::disk(self::DISK)->assertEmpty();
    }

    public function test_a_request_without_a_file_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->post('/admin/uploads', [])->assertSessionHasErrors('image');

        Storage::disk(self::DISK)->assertEmpty();
    }

    public function test_it_is_throttled(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->post('/admin/uploads', ['image' => $this->png()])->assertOk();
        }

        $this->post('/admin/uploads', ['image' => $this->png()])->assertStatus(429);
    }

    /**
     * The disk is a configuration switch, not a hardcoded path. Production needs
     * S3 because a container's local disk is ephemeral; development needs local
     * because it needs no credentials at all.
     */
    public function test_the_disk_comes_from_configuration(): void
    {
        config()->set('images.disk', 'a-different-disk');
        Storage::fake('a-different-disk');

        $this->actingAs(User::factory()->admin()->create());

        $response = $this->post('/admin/uploads', ['image' => $this->png()]);

        $response->assertOk();

        Storage::disk('a-different-disk')->assertExists($response->json('path'));
        Storage::disk(self::DISK)->assertEmpty();
    }

    /**
     * The limits must not be duplicated. They are read from config here, from the
     * form request in production, and the test asserting the error exists at all
     * is what keeps the two from disagreeing.
     */
    public function test_the_allow_list_and_the_limit_come_from_configuration(): void
    {
        $this->assertSame(['image/jpeg', 'image/png', 'image/webp'], config('images.mimetypes'));
        $this->assertGreaterThan(0, (int) config('images.max_kilobytes'));
        $this->assertSame('uploads', config('images.directory'));
    }
}
