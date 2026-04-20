<?php

$autoload = __DIR__ . '/../../../../vendor/autoload.php';

if (!file_exists($autoload)) {
    $autoload = '/var/www/paymenter/vendor/autoload.php';
}

require $autoload;
require __DIR__ . '/TestCase.php';
require __DIR__ . '/LaravelTestCase.php';
