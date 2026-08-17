<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests;

final class TestDatabaseGuard
{
    public static function allows(string $database, string $connection, string $environment): bool
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

        $databaseDirectory = realpath(dirname($database));
        $temporaryDirectory = realpath(sys_get_temp_dir());

        if ($databaseDirectory === false
            || $temporaryDirectory === false
        ) {
            return false;
        }

        $pathsMatch = PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($databaseDirectory, $temporaryDirectory) === 0
            : $databaseDirectory === $temporaryDirectory;

        if (! $pathsMatch
            || preg_match('/^dynamic-pterodactyl-test-[a-z0-9-]+\.sqlite$/i', basename($database)) !== 1
        ) {
            return false;
        }

        if (! file_exists($database)) {
            return true;
        }

        $resolvedDatabase = realpath($database);
        $fileStatus = @stat($database);

        return ! is_link($database)
            && $resolvedDatabase !== false
            && self::pathsEqual(dirname($resolvedDatabase), $temporaryDirectory)
            && is_array($fileStatus)
            && ($fileStatus['nlink'] ?? 0) === 1;
    }

    private static function pathsEqual(string $left, string $right): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }
}
