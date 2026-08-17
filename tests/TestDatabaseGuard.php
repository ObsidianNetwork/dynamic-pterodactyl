<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests;

final class TestDatabaseGuard
{
    /** @var array<string, \PDO> */
    private static array $claimedDatabaseConnections = [];

    private static bool $cleanupRegistered = false;

    public static function claim(
        string $database,
        string $connection,
        string $environment,
        string $host = '',
    ): ?string {
        if ($environment !== 'testing') {
            return null;
        }

        if ($database === 'paymenter_test') {
            return in_array($connection, ['mysql', 'mariadb'], true)
                && self::isLoopbackHost($host)
                    ? $database
                    : null;
        }

        if ($connection !== 'sqlite') {
            return null;
        }

        if ($database === ':memory:') {
            return $database;
        }

        if ($database !== ':temporary:') {
            return null;
        }

        return self::claimIsolatedSqliteDatabase();
    }

    private static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, " \t\n\r\0\x0B[]"));

        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        return is_string($address) && str_starts_with($address, '127.');
    }

    private static function claimIsolatedSqliteDatabase(): ?string
    {
        $database = 'file:dynamic-pterodactyl-test-'.bin2hex(random_bytes(16)).'?mode=memory&cache=shared';

        try {
            $connection = new \PDO('sqlite:'.$database, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (\PDOException) {
            return null;
        }

        self::$claimedDatabaseConnections[$database] = $connection;
        if (! self::$cleanupRegistered) {
            register_shutdown_function(static function (): void {
                self::releaseClaims();
            });
            self::$cleanupRegistered = true;
        }

        return $database;
    }

    private static function releaseClaims(): void
    {
        self::$claimedDatabaseConnections = [];
    }
}
