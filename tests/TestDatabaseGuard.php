<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests;

final class TestDatabaseGuard
{
    /** @var array<string, resource> */
    private static array $claimedDatabaseHandles = [];

    public static function claim(string $database, string $connection, string $environment): bool
    {
        if ($environment !== 'testing') {
            return false;
        }

        if ($database === 'paymenter_test') {
            return in_array($connection, ['mysql', 'mariadb'], true);
        }

        if ($connection !== 'sqlite') {
            return false;
        }

        if ($database === ':memory:') {
            return true;
        }

        return self::claimDisposableSqliteDatabase($database);
    }

    private static function claimDisposableSqliteDatabase(string $database): bool
    {
        $databaseDirectory = dirname($database);
        $directoryParent = realpath(dirname($databaseDirectory));
        $temporaryDirectory = realpath(sys_get_temp_dir());

        if ($directoryParent === false
            || $temporaryDirectory === false
            || ! self::pathsEqual($directoryParent, $temporaryDirectory)
            || preg_match('/^dynamic-pterodactyl-test-[a-z0-9-]+$/i', basename($databaseDirectory)) !== 1
            || basename($database) !== 'database.sqlite'
        ) {
            return false;
        }

        if (! @mkdir($databaseDirectory, 0700)) {
            return false;
        }

        $resolvedDirectory = realpath($databaseDirectory);
        if ($resolvedDirectory === false || ! self::pathsEqual(dirname($resolvedDirectory), $temporaryDirectory)) {
            @rmdir($databaseDirectory);

            return false;
        }

        $handle = @fopen($database, 'x+b');
        $fileStatus = $handle === false ? false : fstat($handle);

        if ($handle === false || ! is_array($fileStatus) || ($fileStatus['nlink'] ?? 0) !== 1) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($database);
            @rmdir($databaseDirectory);

            return false;
        }

        self::$claimedDatabaseHandles[$database] = $handle;

        return true;
    }

    private static function pathsEqual(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }
}
