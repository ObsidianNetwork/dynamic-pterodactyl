<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests;

final class TestDatabaseGuard
{
    /** @var array<string, resource> */
    private static array $claimedDatabaseHandles = [];

    private static bool $cleanupRegistered = false;

    public static function claim(string $database, string $connection, string $environment): ?string
    {
        if ($environment !== 'testing') {
            return null;
        }

        if ($database === 'paymenter_test') {
            return in_array($connection, ['mysql', 'mariadb'], true) ? $database : null;
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

        return self::claimDisposableSqliteDatabase();
    }

    private static function claimDisposableSqliteDatabase(): ?string
    {
        $temporaryDirectory = realpath(sys_get_temp_dir());
        if ($temporaryDirectory === false) {
            return null;
        }

        $databaseDirectory = $temporaryDirectory.DIRECTORY_SEPARATOR
            .'dynamic-pterodactyl-test-'.bin2hex(random_bytes(16));
        if (! @mkdir($databaseDirectory, 0700)) {
            return null;
        }

        $resolvedDirectory = realpath($databaseDirectory);
        if ($resolvedDirectory === false || ! self::pathsEqual(dirname($resolvedDirectory), $temporaryDirectory)) {
            @rmdir($databaseDirectory);

            return null;
        }

        $database = $resolvedDirectory.DIRECTORY_SEPARATOR.'database.sqlite';
        $handle = @fopen($database, 'x+b');
        $fileStatus = $handle === false ? false : fstat($handle);

        if ($handle === false || ! is_array($fileStatus) || ($fileStatus['nlink'] ?? 0) !== 1) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($database);
            @rmdir($databaseDirectory);

            return null;
        }

        self::$claimedDatabaseHandles[$database] = $handle;
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
        foreach (self::$claimedDatabaseHandles as $database => $handle) {
            fclose($handle);
            @unlink($database);
            @rmdir(dirname($database));
        }

        self::$claimedDatabaseHandles = [];
    }

    private static function pathsEqual(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }
}
