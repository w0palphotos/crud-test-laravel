<?php

namespace App\Logs;

use Carbon\Carbon;
use Opcodes\LogViewer\LogLevels\LaravelLogLevel;
use Opcodes\LogViewer\Logs\Log;

/**
 * Membaca baris log JSON Lines dari storage/logs/users/{user_id}/activity-YYYY-MM-DD.log.
 *
 * Setiap baris adalah objek JSON tunggal yang ditulis oleh ActivityLog::record(),
 * sehingga tidak cocok dengan pola `[datetime] env.LEVEL: message` bawaan
 * LaravelLog. Kelas ini memetakan kolom JSON ke kolom tampilan Log Viewer:
 * timestamp → waktu, action → tingkat, subject → pesan, dan sisanya sebagai konteks.
 */
class ActivityJsonLog extends Log
{
    public static string $name = 'Activity JSON';

    public static string $levelClass = LaravelLogLevel::class;

    public static array $columns = [
        ['label' => 'Waktu', 'data_path' => 'datetime'],
        ['label' => 'Aksi', 'data_path' => 'level'],
        ['label' => 'Pengguna', 'data_path' => 'context.email'],
        ['label' => 'Data', 'data_path' => 'message'],
        ['label' => 'Request ID', 'data_path' => 'context.request_id'],
    ];

    public function parseText(array &$matches = []): void
    {
        $decoded = json_decode(trim($this->text), true);

        if (! is_array($decoded)) {
            $this->datetime = null;
            $this->level = '';
            $this->message = $this->text;
            $this->context = [];
            $this->extra = [];

            return;
        }

        $this->datetime = isset($decoded['timestamp'])
            ? Carbon::parse($decoded['timestamp'])
            : null;
        $this->level = $decoded['action'] ?? 'INFO';
        $this->message = trim(sprintf(
            '%s %s',
            $decoded['subject_type'] ?? '',
            $decoded['subject_name'] ?? '',
        ));
        $this->context = [
            'request_id' => $decoded['request_id'] ?? null,
            'user_id' => $decoded['user_id'] ?? null,
            'email' => $decoded['email'] ?? null,
            'ip_address' => $decoded['ip_address'] ?? null,
            'user_agent' => $decoded['user_agent'] ?? null,
            'properties' => $decoded['properties'] ?? null,
        ];
        $this->extra = [];
    }

    public static function matches(string $text, ?int &$timestamp = null, ?string &$level = null): bool
    {
        $decoded = json_decode(trim($text), true);

        if (! is_array($decoded) || ! isset($decoded['action'])) {
            return false;
        }

        if (isset($decoded['timestamp'])) {
            try {
                $timestamp = Carbon::parse($decoded['timestamp'])->timestamp;
            } catch (\Throwable $e) {
                $timestamp = null;
            }
        }

        $level = $decoded['action'] ?? 'INFO';

        return true;
    }

    public function fillMatches(array $matches = []): void
    {
        //
    }
}
