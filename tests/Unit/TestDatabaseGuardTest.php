<?php

namespace Paymenter\Extensions\Others\DynamicPterodactyl\Tests\Unit;

use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\TestCase;
use Paymenter\Extensions\Others\DynamicPterodactyl\Tests\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    public function test_rejects_existing_arbitrary_sqlite_database(): void
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'production-');
        $this->assertNotFalse($temporaryFile);

        $database = $temporaryFile.'.sqlite';
        rename($temporaryFile, $database);

        try {
            $this->assertNull(TestDatabaseGuard::claim($database, 'sqlite', 'testing'));
        } finally {
            unlink($database);
        }
    }

    public function test_allows_disposable_sqlite_database_in_system_temp_directory(): void
    {
        $database = TestDatabaseGuard::claim(':temporary:', 'sqlite', 'testing');

        $this->assertIsString($database);
        $this->assertStringStartsWith(realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR, $database);
        $this->assertFileExists($database);
    }

    public function test_rejects_disposable_name_outside_system_temp_directory(): void
    {
        $database = dirname(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'dynamic-pterodactyl-test-example'
            .DIRECTORY_SEPARATOR.'database.sqlite';

        $this->assertNull(TestDatabaseGuard::claim($database, 'sqlite', 'testing'));
    }

    public function test_rejects_empty_database_name_before_application_boot(): void
    {
        $this->assertNull(TestDatabaseGuard::claim('', 'sqlite', 'testing'));
    }

    public function test_rejects_paymenter_test_database_outside_testing_environment(): void
    {
        $this->assertNull(TestDatabaseGuard::claim('paymenter_test', 'mysql', 'production'));
        $this->assertSame('paymenter_test', TestDatabaseGuard::claim('paymenter_test', 'mariadb', 'testing'));
    }

    public function test_rejects_pre_existing_disposable_database_directory(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $databaseDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dynamic-pterodactyl-test-existing-'.$suffix;
        $database = $databaseDirectory.DIRECTORY_SEPARATOR.'database.sqlite';

        mkdir($databaseDirectory);
        file_put_contents($database, 'must not be treated as a disposable test database');

        try {
            $this->assertNull(TestDatabaseGuard::claim($database, 'sqlite', 'testing'));
        } finally {
            unlink($database);
            rmdir($databaseDirectory);
        }
    }
}
