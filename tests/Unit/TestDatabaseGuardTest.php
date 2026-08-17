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
            $this->assertFalse(TestDatabaseGuard::allows($database, 'sqlite', 'testing'));
        } finally {
            unlink($database);
        }
    }

    public function test_allows_disposable_sqlite_database_in_system_temp_directory(): void
    {
        $database = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dynamic-pterodactyl-test-example.sqlite';

        $this->assertTrue(TestDatabaseGuard::allows($database, 'sqlite', 'testing'));
    }

    public function test_rejects_disposable_name_outside_system_temp_directory(): void
    {
        $database = dirname(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'dynamic-pterodactyl-test-example.sqlite';

        $this->assertFalse(TestDatabaseGuard::allows($database, 'sqlite', 'testing'));
    }

    public function test_rejects_empty_database_name_before_application_boot(): void
    {
        $this->assertFalse(TestDatabaseGuard::allows('', 'sqlite', 'testing'));
    }
}
