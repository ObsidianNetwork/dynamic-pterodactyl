<?php

$autoload = __DIR__ . '/../../../../vendor/autoload.php';

if (!file_exists($autoload)) {
    $autoload = '/var/www/paymenter/vendor/autoload.php';
}

require $autoload;

// dp-13 guard: refuse to boot against a non-test database.
$db = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? '');
if ($db !== 'paymenter_test' && $db !== ':memory:' && $db !== '') {
    fwrite(STDERR, "ABORT: phpunit would run against DB_DATABASE='$db'. "
        . "Expected 'paymenter_test' or ':memory:'. See .sisyphus/notepads/dp-11-authorization-surface-reduction/incidents.md.\n");
    exit(2);
}

require __DIR__ . '/TestCase.php';
require __DIR__ . '/LaravelTestCase.php';
