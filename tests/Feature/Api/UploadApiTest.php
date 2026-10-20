<?php

namespace Tests\Feature\Api;

use App\Actions\StoreUploadedImage;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
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
     * A missing credential is named before the request is made.
     *
     * The storage client does not say "your key is missing". It falls through its
     * credential chain, asks the instance metadata service on the local network,
     * which is not there, and reports that instead — a service the reader has
     * never heard of, saying nothing about the variables that are actually
     * missing, and a second wasted on the timeout before it says it.
     *
     * So the check is made here, against the configuration the application
     * resolved. A value can be present in the dashboard and still be absent here,
     * and that distinction is the whole point of naming a variable.
     */
    public function test_a_missing_credential_is_named_before_anything_is_sent(): void
    {
        config()->set('images.disk', 'unreachable');
        config()->set('filesystems.disks.unreachable', [
            'driver' => 's3',
            'key' => '',
            'secret' => '',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldNotReceive('putFileAs');

        Storage::shouldReceive('disk')->with('unreachable')->andReturn($disk);

        try {
            (new StoreUploadedImage)->handle($this->png());

            $this->fail('A disk with no credentials must not be written to.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AWS_ACCESS_KEY_ID', $e->getMessage());
            $this->assertStringContainsString('AWS_SECRET_ACCESS_KEY', $e->getMessage());

            // And it says what to do about it, which is the part a person needs.
            $this->assertStringContainsString('deploy ulang', $e->getMessage());
        }
    }

    /**
     * A disk that is configured is not second-guessed.
     *
     * The check is about what is missing, not about doubting what is there: a
     * complete configuration must behave exactly as it did before.
     */
    public function test_a_complete_configuration_is_not_second_guessed(): void
    {
        config()->set('images.disk', 'unreachable');
        config()->set('filesystems.disks.unreachable', [
            'driver' => 's3',
            'key' => 'AKIAEXAMPLE',
            'secret' => 'a-secret',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn('uploads/x.jpg');
        $disk->shouldReceive('url')->andReturn('/storage/uploads/x.jpg');

        Storage::shouldReceive('disk')->with('unreachable')->andReturn($disk);

        $this->assertSame('uploads/x.jpg', (new StoreUploadedImage)->handle($this->png())['path']);
    }

    /**
     * The client running out of places to look for a key.
     *
     * The message names the instance metadata service, which is the least useful
     * part of it, and does not name the two variables that are missing. The
     * sentence the reader gets instead names them.
     */
    public function test_the_credential_chain_running_out_is_translated(): void
    {
        config()->set('images.disk', 'unreachable');
        config()->set('filesystems.disks.unreachable', [
            'driver' => 's3',
            'key' => 'AKIAEXAMPLE',
            'secret' => 'a-secret',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andThrow(
            new RuntimeException(
                'Error retrieving credentials from the instance profile metadata service. '
                .'(cURL error 28: Connection timed out after 1001 milliseconds)'
            )
        );

        Storage::shouldReceive('disk')->with('unreachable')->andReturn($disk);

        try {
            (new StoreUploadedImage)->handle($this->png());

            $this->fail('A failed upload must raise.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AWS_ACCESS_KEY_ID', $e->getMessage());
            $this->assertStringNotContainsString('instance profile metadata', $e->getMessage());
        }
    }

    /**
     * A credential the client can find is a credential that works.
     *
     * The client reads the process environment through getenv() and nothing else;
     * the application reads the same value through its own configuration. If the
     * two disagree, the client still works — and a check that only asked the
     * application would refuse an upload that was about to succeed.
     *
     * Refusing a working upload is worse than the second the client spends finding
     * the credential itself.
     */
    public function test_a_credential_only_the_client_can_see_is_not_refused(): void
    {
        putenv('AWS_ACCESS_KEY_ID=from-the-environment');
        putenv('AWS_SECRET_ACCESS_KEY=also-from-the-environment');

        config()->set('images.disk', 'unreachable');
        config()->set('filesystems.disks.unreachable', [
            'driver' => 's3',

            // The application cannot see either of them.
            'key' => null,
            'secret' => null,
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn('uploads/x.jpg');
        $disk->shouldReceive('url')->andReturn('/storage/uploads/x.jpg');

        Storage::shouldReceive('disk')->with('unreachable')->andReturn($disk);

        $this->assertSame('uploads/x.jpg', (new StoreUploadedImage)->handle($this->png())['path']);

        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');
    }

    /**
     * A variable that reached the process empty is a different problem from one
     * that never arrived.
     *
     * They look identical from the outside and are fixed in opposite ways: the
     * first is a value saved without one — which is what happens when a Secret is
     * edited without pasting a new value, because the old one is hidden and cannot
     * be kept — and the second is a scope problem, a name problem, or a deployment
     * that predates the change.
     *
     * So the refusal says which one it is, rather than leaving the reader to work
     * it out from a list of names.
     */
    public function test_a_refusal_says_whether_a_variable_arrived_empty_or_not_at_all(): void
    {
        config()->set('images.disk', 'unreachable');
        config()->set('filesystems.disks.unreachable', [
            'driver' => 's3',
            'key' => '',
            'secret' => '',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        // Present in the process, and empty there.
        putenv('AWS_ACCESS_KEY_ID=');
        putenv('AWS_SECRET_ACCESS_KEY=');

        try {
            (new StoreUploadedImage)->handle($this->png());

            $this->fail('A disk with no credentials must not be written to.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AWS_ACCESS_KEY_ID (ada tapi kosong di process environment)', $e->getMessage());
            $this->assertStringContainsString('AWS_SECRET_ACCESS_KEY (ada tapi kosong di process environment)', $e->getMessage());
        } finally {
            putenv('AWS_ACCESS_KEY_ID');
            putenv('AWS_SECRET_ACCESS_KEY');
        }
    }

    /**
     * A variable that never reached the process says so.
     */
    public function test_a_refusal_says_a_variable_that_never_arrived(): void
    {
        config()->set('images.disk', 'unreachable');
        config()->set('filesystems.disks.unreachable', [
            'driver' => 's3',
            'key' => '',
            'secret' => '',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        putenv('AWS_ACCESS_KEY_ID');
        putenv('AWS_SECRET_ACCESS_KEY');

        try {
            (new StoreUploadedImage)->handle($this->png());

            $this->fail('A disk with no credentials must not be written to.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('AWS_ACCESS_KEY_ID (tidak ada di process environment)', $e->getMessage());
        }
    }

    /**
     * A code with no sentence of its own is still a code.
     *
     * This is the one that cost the most time. The response carried a perfectly
     * good code — AccessControlListNotSupported, naming exactly why the bucket
     * refused the upload — and the message threw it away, because only the codes
     * with a prepared sentence were reported. What the reader saw was a general
     * sentence naming three suspects and the truth in none of them.
     */
    public function test_a_code_without_a_prepared_sentence_is_still_reported(): void
    {
        try {
            $this->uploadFailingWith('SomethingNobodyPredicted');

            $this->fail('A failed upload must raise.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SomethingNobodyPredicted', $e->getMessage());

            // And the client's own words, which is where the useful part is.
            $this->assertStringContainsString('AWS error code', $e->getMessage());
        }
    }

    /**
     * The bucket refusing an ACL, which is what a bucket with owner-enforced
     * ownership does.
     */
    public function test_a_bucket_that_refuses_the_acl_says_so(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('bucket-owner-full-control');

        $this->uploadFailingWith('AccessControlListNotSupported');
    }

    /**
     * A credential or a region the client was never given.
     *
     * The message is the only thing that names which of the two was missing, and
     * it is the one that says so by name.
     */
    public function test_a_missing_credential_says_which_option_is_absent(): void
    {
        config()->set('images.disk', 'unreachable');
        config()->set('filesystems.disks.unreachable', ['driver' => 'local', 'root' => '/tmp']);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andThrow(
            new InvalidArgumentException('Missing required client configuration options: region, key, secret')
        );

        Storage::shouldReceive('disk')->with('unreachable')->andReturn($disk);

        try {
            (new StoreUploadedImage)->handle($this->png());

            $this->fail('A failed upload must raise.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('region, key, secret', $e->getMessage());
        }
    }

    /**
     * A credential never leaves the server.
     *
     * The client's message does not carry one — that is a property of a library
     * rather than a promise this code can make — so the configured key and secret
     * are removed from the text before it goes anywhere. The test puts one in the
     * message to prove it is taken out.
     */
    public function test_a_credential_is_never_sent_to_the_browser(): void
    {
        config()->set('images.disk', 'unreachable');
        config()->set('filesystems.disks.unreachable', [
            'driver' => 'local',
            'root' => '/tmp',
            'key' => 'AKIAEXAMPLEKEY',
            'secret' => 'the-secret-value',
        ]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andThrow(
            new RuntimeException('The key AKIAEXAMPLEKEY and the-secret-value were rejected.')
        );

        Storage::shouldReceive('disk')->with('unreachable')->andReturn($disk);

        try {
            (new StoreUploadedImage)->handle($this->png());

            $this->fail('A failed upload must raise.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('AKIAEXAMPLEKEY', $e->getMessage());
            $this->assertStringNotContainsString('the-secret-value', $e->getMessage());
            $this->assertStringContainsString('***', $e->getMessage());
        }
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
