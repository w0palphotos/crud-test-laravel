<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Melihat riwayat aktivitas pengguna untuk debugging.
 *
 * Hanya admin yang boleh: data diambil dari tabel activity_logs yang dicatat
 * oleh observer setiap create/delete pada entitas yang sales boleh ubah.
 * Disimpan juga di file log per-user (storage/logs/users/{user_id}/activity-YYYY-MM-DD.log)
 * yang terpisah dari laravel.log.
 */
class ActivityLogController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public function index(Request $request): Response
    {
        $query = ActivityLog::query()
            ->with('user')
            ->orderByDesc('created_at');

        // Filter berdasarkan user (route /user/{user}/logs).
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        // Filter berdasarkan email user.
        if ($request->filled('email')) {
            $query->where('email', 'like', '%'.$request->string('email').'%');
        }

        // Filter berdasarkan aksi.
        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        // Filter berdasarkan tipe subjek (model).
        if ($request->filled('subject')) {
            $query->where('subject_type', 'like', '%'.$request->string('subject').'%');
        }

        $paginator = $query->paginate(25)->withQueryString();

        $logs = [
            'data' => collect($paginator->items())->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'email' => $log->email,
                'action' => $log->action,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'subject_name' => $log->subject_name,
                'properties' => $log->properties,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                    'is_admin' => $log->user->is_admin,
                ] : null,
            ])->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'next_page_url' => $paginator->nextPageUrl(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];

        return Inertia::render('Admin/ActivityLogs/Index', [
            'logs' => $logs,
            'filters' => [
                'email' => $request->input('email', ''),
                'action' => $request->input('action', ''),
                'subject' => $request->input('subject', ''),
            ],
            'actions' => ['created', 'updated', 'deleted', 'login', 'logout'],
        ]);
    }

    /**
     * Log aktivitas untuk satu user (route /user/{user}/logs).
     *
     * Reuse logika index, hanya dibatasi ke user_id yang bersangkutan.
     */
    public function forUser(Request $request, User $user): Response
    {
        $request->merge(['user_id' => $user->id]);

        return $this->index($request);
    }
}
