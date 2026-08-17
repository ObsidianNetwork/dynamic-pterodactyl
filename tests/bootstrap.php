<?php

$paymenterBasePath = getenv('PAYMENTER_BASE_PATH');
$autoload = $paymenterBasePath
    ? rtrim($paymenterBasePath, '/\\') . '/vendor/autoload.php'
    : __DIR__ . '/../../../../vendor/autoload.php';

if (!file_exists($autoload)) {
    $autoload = '/var/www/paymenter/vendor/autoload.php';
}

if (!file_exists($autoload)) {
    throw new RuntimeException('Unable to locate Paymenter vendor/autoload.php. Set PAYMENTER_BASE_PATH for standalone checkouts.');
}

$loader = require $autoload;
$loader->addPsr4(
    'Paymenter\\Extensions\\Others\\DynamicPterodactyl\\',
    dirname(__DIR__) . '/',
);

// dp-13 guard: refuse to boot against a non-test database.
$db = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? '');
$connection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? '');
$appEnvironment = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? '');
$isSqliteTestDatabase = $appEnvironment === 'testing'
    && $connection === 'sqlite'
    && ($db === ':memory:' || str_ends_with(strtolower($db), '.sqlite'));

if ($db !== 'paymenter_test' && $db !== '' && ! $isSqliteTestDatabase) {
    fwrite(STDERR, "ABORT: phpunit would run against DB_DATABASE='$db'. "
        . "Expected 'paymenter_test' or a testing-only SQLite database. "
        . "See .sisyphus/notepads/dp-11-authorization-surface-reduction/incidents.md.\n");
    exit(2);
}

require __DIR__ . '/TestCase.php';
require __DIR__ . '/LaravelTestCase.php';
