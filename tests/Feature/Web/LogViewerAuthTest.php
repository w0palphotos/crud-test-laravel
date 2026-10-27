<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogViewerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_log_viewer(): void
    {
        $this->get('/admin/log-viewer')->assertRedirect('/login');
        $this->get('/admin/log-viewer/api/folders')->assertRedirect('/login');
    }

    public function test_non_admin_cannot_access_log_viewer(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/admin/log-viewer')->assertForbidden();
        $this->get('/admin/log-viewer/api/folders')->assertForbidden();
    }

    public function test_admin_can_access_log_viewer_and_api(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/log-viewer')->assertOk();
        $this->get('/admin/log-viewer/api/folders')->assertOk();
    }
}
