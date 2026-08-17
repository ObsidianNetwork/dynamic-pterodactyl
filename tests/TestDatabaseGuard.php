<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests;

final class TestDatabaseGuard
{
    public static function allows(string $database, string $connection, string $environment): bool
    {
        if ($database === 'paymenter_test') {
            return true;
        }

        if ($environment !== 'testing' || $connection !== 'sqlite') {
            return false;
        }

        if ($database === ':memory:') {
            return true;
        }

        $databaseDirectory = realpath(dirname($database));
        $temporaryDirectory = realpath(sys_get_temp_dir());

        return $databaseDirectory !== false
            && $temporaryDirectory !== false
            && strcasecmp($databaseDirectory, $temporaryDirectory) === 0
            && preg_match('/^dynamic-pterodactyl-test-[a-z0-9-]+\.sqlite$/i', basename($database)) === 1;
    }
}
