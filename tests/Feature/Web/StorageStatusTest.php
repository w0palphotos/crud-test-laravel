<?php

namespace Tests\Feature\Web;

use App\Actions\StoreUploadedImage;
use App\Models\User;
use App\Support\Deployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What the application can see of its own image storage.
 *
 * A deployment that cannot write images is a deployment whose configuration is
 * somewhere else, and the reader has no way to tell which of them is wrong. This
 * endpoint is the place that says what the application resolved.
 */
class StorageStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The environment this test runs in, put back afterwards.
     *
     * The endpoint reports what the process environment holds, so a test that
     * inherits an AWS variable from the shell it was launched from would report
     * something the test never arranged. The environment is set here rather than
     * assumed, so the answer is the same on every machine.
     *
     * @var array<string, string|false>
     */
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (array_values(StoreUploadedImage::REQUIRED_OPTIONS) as $variable) {
            $this->environment[$variable] = getenv($variable);

            putenv($variable);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $variable => $value) {
            if ($value === false) {
                putenv($variable);

                continue;
            }

            putenv($variable.'='.$value);
        }

        parent::tearDown();
    }

    public function test_it_reports_what_the_application_resolved(): void
    {
        config()->set('images.disk', 'images');
        config()->set('filesystems.disks.images', [
            'driver' => 's3',
            'key' => 'AKIAEXAMPLE',
            'secret' => 'a-secret',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'url' => 'https://example.supabase.co/storage/v1/object/public/gambar',
            'region' => 'ap-southeast-1',
            'use_path_style_endpoint' => true,
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/uploads/status')
            ->assertOk()
            ->assertJsonPath('disk', 'images')
            ->assertJsonPath('driver', 's3')
            ->assertJsonPath('bucket', 'gambar')
            ->assertJsonPath('region', 'ap-southeast-1')
            ->assertJsonPath('usable', true)
            ->assertJsonPath('variables.AWS_ACCESS_KEY_ID.configured', true)
            ->assertJsonPath('variables.AWS_ACCESS_KEY_ID.usable', true)
            ->assertJsonPath('variables.AWS_SECRET_ACCESS_KEY.configured', true)
            ->assertJsonPath('variables.AWS_BUCKET.configured', true)
            ->assertJsonPath('variables.AWS_ENDPOINT.configured', true)
            ->assertJsonPath('variables.AWS_DEFAULT_REGION.configured', true);
    }

    /**
     * A variable that did not reach the application is reported as missing.
     *
     * This is the whole point of the endpoint: the dashboard can show a value
     * while the running container has none, and only the application knows which.
     */
    public function test_it_names_a_variable_that_did_not_reach_the_application(): void
    {
        config()->set('images.disk', 'images');
        config()->set('filesystems.disks.images', [
            'driver' => 's3',
            'key' => '',
            'secret' => '',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => '',
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/uploads/status')
            ->assertOk()
            ->assertJsonPath('usable', false)
            ->assertJsonPath('variables.AWS_ACCESS_KEY_ID.configured', false)
            ->assertJsonPath('variables.AWS_ACCESS_KEY_ID.usable', false)
            ->assertJsonPath('variables.AWS_SECRET_ACCESS_KEY.configured', false)
            ->assertJsonPath('variables.AWS_DEFAULT_REGION.configured', false)
            ->assertJsonPath('variables.AWS_BUCKET.configured', true);
    }

    /**
     * The two questions the storage client asks, asked separately.
     *
     * The client reads the process environment; the application reads its own
     * configuration. When they disagree the client can still find a credential the
     * application cannot see, and the difference is worth showing rather than
     * flattening into one boolean.
     */
    public function test_it_separates_what_the_process_holds_from_what_the_application_resolved(): void
    {
        putenv('AWS_ACCESS_KEY_ID=from-the-environment');

        config()->set('images.disk', 'images');
        config()->set('filesystems.disks.images', [
            'driver' => 's3',
            'key' => null,
            'secret' => null,
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/uploads/status')
            ->assertOk()
            ->assertJsonPath('variables.AWS_ACCESS_KEY_ID.inEnvironment', true)
            ->assertJsonPath('variables.AWS_ACCESS_KEY_ID.configured', false)
            ->assertJsonPath('variables.AWS_ACCESS_KEY_ID.usable', true)
            ->assertJsonPath('variables.AWS_SECRET_ACCESS_KEY.inEnvironment', false)
            ->assertJsonPath('variables.AWS_SECRET_ACCESS_KEY.configured', false)
            ->assertJsonPath('variables.AWS_SECRET_ACCESS_KEY.usable', false);

        putenv('AWS_ACCESS_KEY_ID');
    }

    /**
     * Which deployment is answering, and how old it is.
     *
     * The environment a deployment was given is fixed when it was built, so a
     * variable added to the dashboard after that is not in it. This is the fact
     * that settles it, and it settles it without anyone comparing timestamps by
     * hand between two different clocks.
     */
    public function test_it_reports_which_deployment_is_answering(): void
    {
        config()->set('images.disk', 'images');
        config()->set('filesystems.disks.images', [
            'driver' => 's3',
            'key' => 'AKIAEXAMPLE',
            'secret' => 'a-secret',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/uploads/status')
            ->assertOk()
            ->assertJsonPath('deployment.builtAt', Deployment::builtAt())
            ->assertJsonPath('deployment.builtAge', Deployment::builtAge())
            ->assertJsonPath('deployment.commit', null)
            ->assertJsonPath('deployment.environment', null);
    }

    /**
     * No credential is reported, only whether one is there.
     */
    public function test_it_never_reports_a_credential(): void
    {
        config()->set('images.disk', 'images');
        config()->set('filesystems.disks.images', [
            'driver' => 's3',
            'key' => 'AKIAEXAMPLEKEY',
            'secret' => 'the-secret-value',
            'bucket' => 'gambar',
            'endpoint' => 'https://example.supabase.co/storage/v1/s3',
            'region' => 'ap-southeast-1',
        ]);

        $this->actingAs(User::factory()->admin()->create());

        $body = $this->get('/admin/uploads/status')->assertOk()->getContent();

        $this->assertStringNotContainsString('AKIAEXAMPLEKEY', $body);
        $this->assertStringNotContainsString('the-secret-value', $body);
    }

    /**
     * It is behind the administrator boundary, like the rest of the desk.
     */
    public function test_an_ordinary_account_cannot_read_it(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/uploads/status')->assertForbidden();
    }

    public function test_a_guest_cannot_read_it(): void
    {
        $this->get('/admin/uploads/status')->assertRedirect(route('login'));
    }
}
