<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Fail-closed guard for every automated path that may write to a database.
 *
 * The ONLY database automated tests and concurrency actors may touch is
 * {@see self::DATABASE} under the {@see self::ENVIRONMENT} environment.
 * Exact equality only — no pattern matching, so `foo_testing`,
 * `primeclassy_dev` and `sql_prime` are all refused.
 *
 * The check is pure (values in, exception out) so it can be unit-tested
 * without connecting to any forbidden database.
 */
final class TestDatabaseGuard
{
    public const ENVIRONMENT = 'testing';

    public const DATABASE = 'primeclassy_testing';

    public static function assertSafe(?string $environment, mixed $connection, mixed $database): void
    {
        if ($environment !== self::ENVIRONMENT
            || ! in_array($connection, ['mysql', 'mariadb'], true)
            || $database !== self::DATABASE) {
            throw new RuntimeException(sprintf(
                'REFUSED: automated tests may only use APP_ENV=%s with database %s (got environment: %s, connection: %s, database: %s).',
                self::ENVIRONMENT,
                self::DATABASE,
                var_export($environment, true),
                var_export($connection, true),
                var_export($database, true),
            ));
        }
    }

    /** Validates the booted application's resolved configuration (no DB access). */
    public static function assertApplicationSafe(object $app): void
    {
        $connection = $app['config']->get('database.default');
        $database = is_string($connection)
            ? $app['config']->get("database.connections.{$connection}.database")
            : null;

        self::assertSafe($app->environment(), $connection, $database);
    }
}
