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

    public function test_creates_isolated_named_memory_sqlite_database(): void
    {
        $database = TestDatabaseGuard::claim(':temporary:', 'sqlite', 'testing');
        $secondDatabase = TestDatabaseGuard::claim(':temporary:', 'sqlite', 'testing');

        $this->assertIsString($database);
        $this->assertIsString($secondDatabase);
        $this->assertNotSame($database, $secondDatabase);
        $this->assertStringStartsWith('file:dynamic-pterodactyl-test-', $database);
        $this->assertStringEndsWith('?mode=memory&cache=shared', $database);

        $writer = new \PDO('sqlite:'.$database);
        $writer->exec('CREATE TABLE claim_probe (value TEXT NOT NULL)');
        $writer->exec("INSERT INTO claim_probe (value) VALUES ('owned')");
        unset($writer);

        $reader = new \PDO('sqlite:'.$database);
        $this->assertSame('owned', $reader->query('SELECT value FROM claim_probe')->fetchColumn());
    }

    public function test_rejects_caller_supplied_named_memory_database(): void
    {
        $this->assertNull(TestDatabaseGuard::claim(
            'file:shared-production?mode=memory&cache=shared',
            'sqlite',
            'testing',
        ));
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
