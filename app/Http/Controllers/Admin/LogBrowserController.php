<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\SystemErrorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Penjelajah log ala /var/log: menampilkan pohon direktori log secara rekursif.
 *
 * Di lingkungan serverless (seperti Vercel), disk lokal bersifat ephemeral
 * sehingga log disimpan persisten ke database (tabel activity_logs & system_error_logs).
 * Controller ini menggabungkan data persisten dari database dengan file lokal
 * di storage/logs/, sehingga riwayat log per user dan error tidak pernah hilang.
 */
class LogBrowserController extends Controller
{
    /**
     * Halaman utama: pohon direktori + isi file yang dipilih (via ?file=).
     */
    public function index(Request $request): Response
    {
        $tree = $this->buildCombinedTree();

        $relative = $request->query('file');
        $selected = null;
        $type = null;
        $entries = [];
        $raw = null;
        $error = null;

        if (is_string($relative) && $relative !== '') {
            [$type, $entries, $raw, $error] = $this->loadLogContent($relative);
            if ($error === null) {
                $selected = $relative;
            }
        }

        return Inertia::render('Admin/Logs/Index', [
            'tree' => $tree,
            'selectedFile' => $selected,
            'type' => $type,
            'entries' => $entries,
            'raw' => $raw,
            'error' => $error,
        ]);
    }

    /**
     * Muat isi log baik dari database (prioritas utama/persistent) atau disk fisik.
     *
     * @return array{0: string|null, 1: list<array<string, mixed>>, 2: string|null, 3: string|null}
     */
    private function loadLogContent(string $relative): array
    {
        // 1. Cek aktivitas user dari database: users/{user_id}/activity-YYYY-MM-DD.log
        if (preg_match('#^users/([^/]+)/activity-(\d{4}-\d{2}-\d{2})\.log$#', $relative, $m)) {
            $userId = $m[1] === 'system' ? null : (int) $m[1];
            $date = $m[2];

            $logs = ActivityLog::query()
                ->where('user_id', $userId)
                ->whereDate('created_at', $date)
                ->orderByDesc('id')
                ->get();

            if ($logs->isNotEmpty()) {
                $entries = $logs->map(fn (ActivityLog $l): array => $this->formatActivityEntry($l))->all();
                $raw = implode("\n", $logs->map(fn (ActivityLog $l): string => $this->formatActivityRaw($l))->all());

                return ['activity', $entries, $raw, null];
            }
        }

        // 2. Cek system error dari database: coding-errors/... atau other-errors/...
        if (preg_match('#^(coding-errors|other-errors)/\1-(\d{4}-\d{2}-\d{2})\.log$#', $relative, $m)) {
            $channel = $m[1];
            $date = $m[2];

            $errors = SystemErrorLog::query()
                ->where('channel', $channel)
                ->whereDate('created_at', $date)
                ->orderByDesc('id')
                ->get();

            if ($errors->isNotEmpty()) {
                $entries = $errors->map(fn (SystemErrorLog $e): array => $this->formatErrorEntry($e))->all();
                $raw = implode("\n\n", $errors->map(fn (SystemErrorLog $e): string => $this->formatErrorRaw($e))->all());

                return ['error', $entries, $raw, null];
            }
        }

        // 3. Fallback ke disk fisik storage/logs/ (misal file laravel.log atau log lokal)
        $full = $this->resolvePath($relative);
        if ($full === null) {
            return [null, [], null, 'Path tidak valid atau di luar direktori log.'];
        }

        if (! is_file($full)) {
            return [null, [], null, 'File log tidak ditemukan.'];
        }

        $raw = @file_get_contents($full) ?: '';
        [$type, $entries] = $this->parsePhysicalFile($full, $raw);

        return [$type, $entries, $raw, null];
    }

    /**
     * Bangun pohon direktori gabungan database + disk fisik.
     *
     * @return list<array<string, mixed>>
     */
    private function buildCombinedTree(): array
    {
        $virtual = [];

        // 1. Pohon error dari database (coding-errors & other-errors)
        try {
            if (Schema::hasTable('system_error_logs')) {
                foreach (['coding-errors', 'other-errors'] as $ch) {
                    $rows = SystemErrorLog::query()
                        ->where('channel', $ch)
                        ->selectRaw('DATE(created_at) as log_date, count(*) as count, max(created_at) as last_modified')
                        ->groupBy(DB::raw('DATE(created_at)'))
                        ->orderByDesc('log_date')
                        ->get();

                    if ($rows->isNotEmpty()) {
                        $files = [];
                        foreach ($rows as $r) {
                            $files[] = [
                                'name' => "{$ch}-{$r->log_date}.log",
                                'path' => "{$ch}/{$ch}-{$r->log_date}.log",
                                'type' => 'file',
                                'size' => $r->count * 1024,
                                'modified' => (string) $r->last_modified,
                            ];
                        }
                        $virtual[$ch] = [
                            'name' => $ch,
                            'path' => $ch,
                            'type' => 'folder',
                            'children' => $files,
                        ];
                    }
                }
            }
        } catch (\Throwable) {
        }

        // 2. Pohon aktivitas user dari database (users/{user_id}/activity-YYYY-MM-DD.log)
        try {
            if (Schema::hasTable('activity_logs')) {
                $rows = ActivityLog::query()
                    ->selectRaw('user_id, DATE(created_at) as log_date, count(*) as count, max(created_at) as last_modified')
                    ->groupBy('user_id', DB::raw('DATE(created_at)'))
                    ->orderBy('user_id')
                    ->orderByDesc('log_date')
                    ->get();

                if ($rows->isNotEmpty()) {
                    $userGroups = $rows->groupBy(fn ($item): string => (string) ($item->user_id ?? 'system'));
                    $userFolders = [];

                    foreach ($userGroups as $userId => $items) {
                        $files = [];
                        foreach ($items as $r) {
                            $files[] = [
                                'name' => "activity-{$r->log_date}.log",
                                'path' => "users/{$userId}/activity-{$r->log_date}.log",
                                'type' => 'file',
                                'size' => $r->count * 256,
                                'modified' => (string) $r->last_modified,
                            ];
                        }

                        $userFolders[] = [
                            'name' => (string) $userId,
                            'path' => "users/{$userId}",
                            'type' => 'folder',
                            'children' => $files,
                        ];
                    }

                    $virtual['users'] = [
                        'name' => 'users',
                        'path' => 'users',
                        'type' => 'folder',
                        'children' => $userFolders,
                    ];
                }
            }
        } catch (\Throwable) {
        }

        // 3. Gabungkan dengan file fisik di storage/logs/ (jika ada)
        $physical = $this->buildPhysicalTree(storage_path('logs'));

        return $this->mergeTrees($virtual, $physical);
    }

    /**
     * @param  array<string, array<string, mixed>>  $virtual
     * @param  list<array<string, mixed>>  $physical
     * @return list<array<string, mixed>>
     */
    private function mergeTrees(array $virtual, array $physical): array
    {
        $merged = $virtual;

        foreach ($physical as $pNode) {
            $name = $pNode['name'];
            if (! isset($merged[$name])) {
                $merged[$name] = $pNode;

                continue;
            }

            if ($merged[$name]['type'] === 'folder' && $pNode['type'] === 'folder') {
                $existingPaths = array_column($merged[$name]['children'], 'path');
                foreach ($pNode['children'] as $child) {
                    if (! in_array($child['path'], $existingPaths, true)) {
                        $merged[$name]['children'][] = $child;
                    }
                }
            }
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * Scan disk fisik storage/logs/ secara rekursif.
     *
     * @return list<array<string, mixed>>
     */
    private function buildPhysicalTree(string $base, string $relative = ''): array
    {
        $nodes = [];
        $items = @scandir($base);
        if ($items === false) {
            return $nodes;
        }

        sort($items);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $base.DIRECTORY_SEPARATOR.$item;
            $rel = $relative === '' ? $item : $relative.DIRECTORY_SEPARATOR.$item;

            if (is_dir($full)) {
                $children = $this->buildPhysicalTree($full, $rel);
                if ($children === []) {
                    continue;
                }
                $nodes[] = [
                    'name' => $item,
                    'path' => $rel,
                    'type' => 'folder',
                    'children' => $children,
                ];
            } elseif (is_file($full) && str_ends_with($item, '.log')) {
                $nodes[] = [
                    'name' => $item,
                    'path' => $rel,
                    'type' => 'file',
                    'size' => filesize($full) ?: 0,
                    'modified' => date('Y-m-d H:i:s', (int) filemtime($full)),
                ];
            }
        }

        return $nodes;
    }

    private function resolvePath(string $relative): ?string
    {
        $base = realpath(storage_path('logs'));
        if ($base === false) {
            return null;
        }

        $full = realpath($base.DIRECTORY_SEPARATOR.$relative);
        if ($full === false) {
            return null;
        }

        $baseWithSep = $base.DIRECTORY_SEPARATOR;
        if (strpos($full, $baseWithSep) !== 0) {
            return null;
        }

        return $full;
    }

    /**
     * @return array{timestamp: string|null, request_id: string|null, user_id: int|null, email: string|null, action: string|null, subject_type: string|null, subject_name: string|null, subject_id: int|null, ip_address: string|null, user_agent: string|null, properties: mixed}
     */
    private function formatActivityEntry(ActivityLog $log): array
    {
        return [
            'timestamp' => $log->created_at?->format('Y-m-d H:i:s'),
            'request_id' => $log->request_id,
            'user_id' => $log->user_id,
            'email' => $log->email,
            'action' => $log->action,
            'subject_type' => $log->subject_type ? (string) class_basename($log->subject_type) : null,
            'subject_name' => $log->subject_name,
            'subject_id' => $log->subject_id,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'properties' => $log->properties,
        ];
    }

    private function formatActivityRaw(ActivityLog $log): string
    {
        return (string) json_encode([
            'request_id' => $log->request_id,
            'timestamp' => $log->created_at?->format('Y-m-d H:i:s'),
            'user_id' => $log->user_id,
            'email' => $log->email,
            'action' => $log->action,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'subject_name' => $log->subject_name,
            'properties' => $log->properties,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{timestamp: string|null, environment: string, level: string, message: string, stack: string}
     */
    private function formatErrorEntry(SystemErrorLog $error): array
    {
        return [
            'timestamp' => $error->created_at?->format('Y-m-d H:i:s'),
            'environment' => $error->environment,
            'level' => strtoupper($error->level),
            'message' => $error->message,
            'stack' => $error->stack ?? '',
        ];
    }

    private function formatErrorRaw(SystemErrorLog $error): string
    {
        $line = "[{$error->created_at?->format('Y-m-d H:i:s')}] {$error->environment}.{$error->level}: {$error->message}";
        if ($error->stack) {
            $line .= "\n".$error->stack;
        }

        return $line;
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function parsePhysicalFile(string $fullPath, string $content): array
    {
        $basename = basename($fullPath);

        if (str_contains($fullPath, DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR)
            || str_starts_with($basename, 'activity-')) {
            return ['activity', $this->parsePhysicalActivity($content)];
        }

        return ['error', $this->parsePhysicalError($content)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parsePhysicalActivity(string $content): array
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }
            $entries[] = [
                'timestamp' => $decoded['timestamp'] ?? null,
                'request_id' => $decoded['request_id'] ?? null,
                'user_id' => $decoded['user_id'] ?? null,
                'email' => $decoded['email'] ?? null,
                'action' => $decoded['action'] ?? null,
                'subject_type' => isset($decoded['subject_type']) ? (string) (class_basename($decoded['subject_type'])) : null,
                'subject_name' => $decoded['subject_name'] ?? null,
                'subject_id' => $decoded['subject_id'] ?? null,
                'ip_address' => $decoded['ip_address'] ?? null,
                'user_agent' => $decoded['user_agent'] ?? null,
                'properties' => $decoded['properties'] ?? null,
            ];
        }

        return array_reverse($entries);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parsePhysicalError(string $content): array
    {
        $entries = [];
        $current = null;

        foreach (preg_split('/\r?\n/', $content) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(\S+)\.(\w+):\s*(.*)$/', $line, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = [
                    'timestamp' => $m[1],
                    'environment' => $m[2],
                    'level' => strtoupper($m[3]),
                    'message' => $m[4],
                    'stack' => '',
                ];
            } elseif ($current !== null && trim($line) !== '') {
                $current['stack'] .= $line."\n";
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        $limit = 500;
        if (count($entries) > $limit) {
            $entries = array_slice($entries, -$limit);
        }

        return array_reverse($entries);
    }
}
