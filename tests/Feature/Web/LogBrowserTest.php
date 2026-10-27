<?php

namespace Tests\Feature\Web;

use App\Models\ActivityLog;
use App\Models\SystemErrorLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LogBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_log_browser(): void
    {
        $this->get('/admin/logs')->assertRedirect('/login');
    }

    public function test_non_admin_cannot_access_log_browser(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/admin/logs')->assertForbidden();
    }

    public function test_admin_can_view_log_browser_tree_from_database(): void
    {
        $user = User::factory()->admin()->create();

        ActivityLog::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'action' => 'created',
            'subject_type' => 'App\\Models\\Product',
            'subject_id' => 10,
            'subject_name' => 'Knalpot',
            'request_id' => 'test-req-id',
            'created_at' => '2026-10-27 10:00:00',
        ]);

        SystemErrorLog::create([
            'channel' => 'coding-errors',
            'level' => 'ERROR',
            'message' => 'Simulated error',
            'environment' => 'testing',
            'created_at' => '2026-10-27 10:05:00',
        ]);

        $this->actingAs($user)
            ->get('/admin/logs')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Logs/Index')
                ->has('tree')
            );
    }

    public function test_admin_can_read_user_activity_log_from_database(): void
    {
        $user = User::factory()->admin()->create();

        ActivityLog::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'action' => 'created',
            'subject_type' => 'App\\Models\\Product',
            'subject_id' => 10,
            'subject_name' => 'Knalpot',
            'request_id' => 'req-123',
            'created_at' => '2026-10-27 10:00:00',
        ]);

        $this->actingAs($user)
            ->get("/admin/logs?file=users/{$user->id}/activity-2026-10-27.log")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Logs/Index')
                ->where('selectedFile', "users/{$user->id}/activity-2026-10-27.log")
                ->where('type', 'activity')
                ->has('entries', 1)
                ->where('entries.0.request_id', 'req-123')
            );
    }

    public function test_admin_can_read_error_log_from_database(): void
    {
        $user = User::factory()->admin()->create();

        SystemErrorLog::create([
            'channel' => 'coding-errors',
            'level' => 'CRITICAL',
            'message' => 'Simulated crash',
            'stack' => '#0 dummy.php',
            'environment' => 'testing',
            'created_at' => '2026-10-27 11:00:00',
        ]);

        $this->actingAs($user)
            ->get('/admin/logs?file=coding-errors/coding-errors-2026-10-27.log')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Logs/Index')
                ->where('selectedFile', 'coding-errors/coding-errors-2026-10-27.log')
                ->where('type', 'error')
                ->has('entries', 1)
                ->where('entries.0.level', 'CRITICAL')
                ->where('entries.0.message', 'Simulated crash')
            );
    }
}
