<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class SlackErrorNotifier
{
    /**
     * Notify Slack about fatal or critical errors.
     * Includes anti-flood rate limit (debounce by error hash for 5 minutes).
     */
    public static function notify(Throwable $e): void
    {
        $webhookUrl = config('logging.channels.slack.url');
        if (! is_string($webhookUrl) || trim($webhookUrl) === '') {
            return;
        }

        // Debounce: prevent spamming Slack if the same error repeats in a loop
        $errorHash = 'slack_err_'.md5(get_class($e).':'.$e->getMessage().':'.$e->getFile().':'.$e->getLine());
        if (Cache::has($errorHash)) {
            return;
        }
        Cache::put($errorHash, true, now()->addMinutes(5));

        $env = app()->environment();
        $appName = config('app.name', 'Laravel');
        $request = request();

        $url = $request ? $request->fullUrl() : 'CLI / Background Job';
        $method = $request ? $request->method() : 'CLI';
        $user = $request?->user();
        $userInfo = $user ? "{$user->email} (ID: {$user->id})" : 'Guest / System';

        $exceptionClass = get_class($e);

        $payload = [
            'text' => "[{$env}] 🚨 Fatal Error: {$e->getMessage()}",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => "🚨 [{$env}] {$appName} - Fatal Error",
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Exception:*\n`{$exceptionClass}`",
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Environment:*\n`{$env}`",
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Request:*\n`{$method} {$url}`",
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*User:*\n{$userInfo}",
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Message:*\n```{$e->getMessage()}```",
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Location:*\n`{$e->getFile()}:{$e->getLine()}`",
                    ],
                ],
            ],
        ];

        try {
            Http::timeout(3)->post($webhookUrl, $payload);
        } catch (Throwable) {
            // Fail silently to avoid masking the original exception
        }
    }
}
