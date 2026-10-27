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

    public function test_admin_can_sync_and_open_in_log_viewer(): void
    {
        $user = User::factory()->admin()->create();

        ActivityLog::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'action' => 'created',
            'subject_type' => 'App\\Models\\Product',
            'subject_id' => 10,
            'subject_name' => 'Knalpot',
            'request_id' => 'req-abc',
            'created_at' => '2026-10-27 12:00:00',
        ]);

        $filePath = "users/{$user->id}/activity-2026-10-27.log";

        $response = $this->actingAs($user)
            ->get("/admin/logs/open?file={$filePath}");

        $response->assertRedirect();
        $this->assertFileExists(storage_path("logs/{$filePath}"));

        // Cleanup created file
        @unlink(storage_path("logs/{$filePath}"));
        @rmdir(storage_path("logs/users/{$user->id}"));
    }

    public function test_large_log_file_returns_warning_and_tail_content(): void
    {
        $user = User::factory()->admin()->create();

        // Create a fake large file > 3MB
        $filename = 'test-large.log';
        $fullPath = storage_path("logs/{$filename}");
        $fp = fopen($fullPath, 'w');
        fseek($fp, 3.5 * 1024 * 1024);
        fwrite($fp, "\n[2026-10-27 12:30:00] local.ERROR: Big file error message\n");
        fclose($fp);

        try {
            $this->actingAs($user)
                ->get("/admin/logs?file={$filename}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Admin/Logs/Index')
                    ->where('selectedFile', $filename)
                    ->where('type', 'error')
                    ->has('warning')
                    ->whereNot('warning', null)
                );
        } finally {
            @unlink($fullPath);
        }
    }
}
