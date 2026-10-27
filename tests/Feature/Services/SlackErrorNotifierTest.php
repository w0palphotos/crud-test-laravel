<?php

namespace Tests\Feature\Services;

use App\Services\SlackErrorNotifier;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlackErrorNotifierTest extends TestCase
{
    public function test_it_does_not_send_when_webhook_url_is_empty(): void
    {
        Http::fake();
        Config::set('logging.channels.slack.url', null);

        SlackErrorNotifier::notify(new Exception('Boom'));

        Http::assertNothingSent();
    }

    public function test_it_sends_formatted_notification_to_slack(): void
    {
        Http::fake();
        Cache::flush();

        $webhookUrl = 'https://hooks.slack.com/services/test/webhook';
        Config::set('logging.channels.slack.url', $webhookUrl);

        $exception = new Exception('Database connection failed');
        SlackErrorNotifier::notify($exception);

        Http::assertSent(function ($request) use ($webhookUrl) {
            return $request->url() === $webhookUrl
                && str_contains($request['text'], 'Database connection failed')
                && isset($request['blocks'])
                && count($request['blocks']) >= 4;
        });
    }

    public function test_it_debounces_repeated_identical_errors(): void
    {
        Http::fake();
        Cache::flush();

        $webhookUrl = 'https://hooks.slack.com/services/test/webhook';
        Config::set('logging.channels.slack.url', $webhookUrl);

        $exception = new Exception('Same repeated error');

        SlackErrorNotifier::notify($exception);
        SlackErrorNotifier::notify($exception);
        SlackErrorNotifier::notify($exception);

        Http::assertSentCount(1);
    }
}
