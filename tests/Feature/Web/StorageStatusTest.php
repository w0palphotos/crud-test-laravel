<?php

namespace Tests\Feature\Web;

use App\Models\User;
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
            ->assertJsonPath('variables.AWS_ACCESS_KEY_ID', true)
            ->assertJsonPath('variables.AWS_SECRET_ACCESS_KEY', true)
            ->assertJsonPath('variables.AWS_BUCKET', true)
            ->assertJsonPath('variables.AWS_ENDPOINT', true)
            ->assertJsonPath('variables.AWS_DEFAULT_REGION', true);
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
            ->assertJsonPath('variables.AWS_ACCESS_KEY_ID', false)
            ->assertJsonPath('variables.AWS_SECRET_ACCESS_KEY', false)
            ->assertJsonPath('variables.AWS_DEFAULT_REGION', false)
            ->assertJsonPath('variables.AWS_BUCKET', true);
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
