<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Penjelajah log ala /var/log: menampilkan pohon direktori storage/logs/
 * secara rekursif, dengan isi file yang sudah diparse (aktivitas JSON → tabel,
 * error Monolog → baris dengan level & stack trace) agar lebih mudah dibaca
 * daripada raw text.
 */
class LogBrowserController extends Controller
{
    /**
     * Halaman utama: pohon direktori + isi file yang dipilih (via ?file=).
     */
    public function index(Request $request): Response
    {
        $tree = $this->buildTree(storage_path('logs'));

        $relative = $request->query('file');
        $selected = null;
        $type = null;
        $entries = [];
        $raw = null;
        $error = null;

        if (is_string($relative) && $relative !== '') {
            $full = $this->resolvePath($relative);
            if ($full === null) {
                $error = 'Path tidak valid atau di luar direktori log.';
            } elseif (! is_file($full)) {
                $error = 'File tidak ditemukan.';
            } else {
                $selected = $relative;
                $raw = @file_get_contents($full) ?: '';
                [$type, $entries] = $this->parse($full, $raw);
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
     * Bangun pohon direktori secara rekursif. Hanya file .log yang dimasukkan.
     *
     * @return list<array<string, mixed>>
     */
    private function buildTree(string $base, string $relative = ''): array
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
                $children = $this->buildTree($full, $rel);
                // Sembunyikan folder kosong agar tree tetap rapi.
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

    /**
     * Pastikan path berada di dalam storage/logs (cegah directory traversal).
     */
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
     * Parse isi file menjadi entri terstruktur.
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function parse(string $fullPath, string $content): array
    {
        $basename = basename($fullPath);

        // File aktivitas user: JSON Lines (satu objek JSON per baris).
        if (str_contains($fullPath, DIRECTORY_SEPARATOR.'users'.DIRECTORY_SEPARATOR)
            || str_starts_with($basename, 'activity-')) {
            return ['activity', $this->parseActivity($content)];
        }

        // File error: format Monolog Laravel.
        return ['error', $this->parseError($content)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseActivity(string $content): array
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

        // Urutkan terbaru dulu (file ditulis append, jadi reverse = terbaru di atas).
        return array_reverse($entries);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseError(string $content): array
    {
        $entries = [];
        $current = null;

        // Monolog format: [YYYY-MM-DD HH:MM:SS] env.LEVEL: message
        // Baris lanjutan (stack trace, context) digabung ke entri sebelumnya.
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

        // Batasi jumlah entri agar halaman tidak terlalu berat (ambil yang terbaru).
        $limit = 500;
        if (count($entries) > $limit) {
            $entries = array_slice($entries, -$limit);
        }

        return array_reverse($entries);
    }
}
