<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'email', 'action', 'subject_type', 'subject_id', 'subject_name', 'properties', 'ip_address', 'user_agent', 'request_id'])]
class ActivityLog extends Model
{
    use HasFactory;

    public $timestamps = true;

    protected $casts = [
        'properties' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Catat satu aksi pengguna ke activity_logs dan ke file log per-user.
     *
     * @param  array{subject: mixed, action: string, name?: string, properties?: array<string, mixed>}  $context
     */
    public static function record(array $context): static
    {
        $user = auth()->user();
        $subject = $context['subject'];

        // Human-readable label: override via `name` in context, otherwise fall
        // back to a name-like attribute on the subject (Eloquent attributes are
        // reached through __get, not declared properties, so `isset` is the
        // right check), then the primary key.
        if (isset($context['name'])) {
            $subjectName = $context['name'];
        } elseif (isset($subject->name)) {
            $subjectName = $subject->name;
        } elseif (isset($subject->reference)) {
            $subjectName = $subject->reference;
        } else {
            $subjectName = (string) $subject->getKey();
        }

        // request_id diturunkan satu kali per request agar semua aktivitas yang
        // timbul dalam satu request dapat dikorelasikan (misal penjualan + item).
        $requestId = app()->bound('activity.request_id')
            ? app('activity.request_id')
            : (string) Str::uuid();

        try {
            $log = static::create([
                'user_id' => $user?->id,
                'email' => $user?->email ?? 'system',
                'action' => $context['action'],
                'subject_type' => get_class($subject),
                'subject_id' => $subject->getKey(),
                'subject_name' => $subjectName,
                'properties' => $context['properties'] ?? [],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'request_id' => $requestId,
            ]);

            // Tulis ke file log per-user: storage/logs/users/{user_id}/activity-YYYY-MM-DD.log
            // dalam bentuk JSON Lines (satu objek JSON per baris), rapi untuk
            // dibaca dan cocok untuk Log Viewer. Admin UI tetap baca dari tabel
            // activity_logs untuk semua user.
            static::writeToUserLogFile($user?->id, json_encode([
                'request_id' => $requestId,
                'timestamp' => $log->created_at?->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
                'user_id' => $user?->id,
                'email' => $user?->email ?? 'system',
                'action' => $context['action'],
                'subject_type' => get_class($subject),
                'subject_id' => $subject->getKey(),
                'subject_name' => $subjectName,
                'properties' => $context['properties'] ?? [],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            \Log::channel('coding-errors')->error(
                'ActivityLog::record failed: '.$e->getMessage(),
                ['exception' => get_class($e), 'subject' => get_class($subject)],
            );
        }

        return new static;
    }

    /**
     * Tulis satu baris log JSON ke file per-user di storage/logs/users/{user_id}/activity-YYYY-MM-DD.log.
     *
     * Setiap user punya file log sendiri sehingga tidak tercampur dengan user
     * lain. Admin UI tetap membaca dari tabel activity_logs untuk semua user.
     */
    private static function writeToUserLogFile(?int $userId, string $message): void
    {
        if (! $userId) {
            return;
        }

        $dir = storage_path('logs/users/'.$userId);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Klasifikasi per tanggal (YYYY-MM-DD) agar file per hari terpisah dan
        // lebih mudah dibaca di Log Viewer / filesystem.
        $path = $dir.'/activity-'.now()->format('Y-m-d').'.log';
        @file_put_contents($path, $message.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
