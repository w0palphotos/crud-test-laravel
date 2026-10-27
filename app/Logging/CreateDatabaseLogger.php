<?php

namespace App\Logging;

use Monolog\Logger;

class CreateDatabaseLogger
{
    /**
     * Create a custom Monolog instance.
     *
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        $handler = new DatabaseLogHandler(
            level: $config['level'] ?? 'debug'
        );

        return new Logger('database', [$handler]);
    }
}
