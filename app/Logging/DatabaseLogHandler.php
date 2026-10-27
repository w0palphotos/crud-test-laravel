<?php

namespace App\Logging;

use App\Models\SystemErrorLog;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Throwable;

class DatabaseLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        try {
            if (! Schema::hasTable('system_error_logs')) {
                return;
            }

            $channel = $record->channel === 'other-errors' ? 'other-errors' : 'coding-errors';
            $stack = null;
            $context = $record->context;

            if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
                $stack = $context['exception']->getTraceAsString();
                $context['exception'] = [
                    'class' => get_class($context['exception']),
                    'message' => $context['exception']->getMessage(),
                    'code' => $context['exception']->getCode(),
                    'file' => $context['exception']->getFile().':'.$context['exception']->getLine(),
                ];
            }

            SystemErrorLog::create([
                'channel' => $channel,
                'level' => $record->level->getName(),
                'message' => $record->message,
                'stack' => $stack,
                'context' => ! empty($context) ? $context : null,
                'environment' => app()->environment(),
                'created_at' => $record->datetime->format('Y-m-d H:i:s'),
                'updated_at' => $record->datetime->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // Logging to database must never crash the application.
        }
    }
}
