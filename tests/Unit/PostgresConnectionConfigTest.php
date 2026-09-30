<?php

namespace Tests\Unit;

use Illuminate\Support\ConfigurationUrlParser;
use PDO;
use Tests\TestCase;

class PostgresConnectionConfigTest extends TestCase
{
    public function test_the_pgsql_options_are_an_array_of_pdo_attributes(): void
    {
        $options = config('database.connections.pgsql.options');

        $this->assertIsArray($options, 'PDO options must be an array. A string here makes Connector::getOptions() fail with a TypeError on every query.');
    }

    /**
     * The failure this guards against is subtle: DB_URL is merged into the
     * connection config by ConfigurationUrlParser, which copies query parameters
     * across verbatim. Supabase's own guidance for its transaction-mode pooler is
     * to add options=--statement_cache_size=0 to the connection string, but that
     * is a libpq directive, not a PDO attribute, so it arrives here as a string
     * and breaks every query at runtime instead of at boot.
     */
    public function test_a_libpq_options_parameter_in_the_url_is_rejected(): void
    {
        $url = 'postgresql://postgres.REF:pw@aws-0-eu-central-1.pooler.supabase.com:6543/postgres'
            .'?sslmode=require&options=--statement_cache_size%3D0';

        $parsed = (new ConfigurationUrlParser)->parseConfiguration(
            ['driver' => 'pgsql', 'url' => $url]
        );

        $this->assertIsString(
            $parsed['options'] ?? null,
            'This is the shape that breaks: the parser hands libpq directives through as a string.',
        );

        $this->assertIsArray(
            config('database.connections.pgsql.options'),
            'The real config must stay an array, and the URL must not supply options.',
        );
    }

    public function test_prepared_statements_are_emulated_for_the_pooler(): void
    {
        $this->assertFalse(
            config('database.connections.pgsql.options')[PDO::ATTR_EMULATE_PREPARES] ?? null,
            'Transaction-mode pooling requires emulated prepares.',
        );
    }

    public function test_sslmode_defaults_to_something_that_encrypts(): void
    {
        $this->assertSame('prefer', config('database.connections.pgsql.sslmode'));
    }
}
