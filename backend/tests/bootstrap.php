<?php

require dirname(__DIR__).'/vendor/autoload.php';

// Each test process gets an independent encryption key, including cookie tests.
$_SERVER['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));

if (getenv('TEST_DB_CONNECTION') === 'pgsql') {
    $database = getenv('TEST_DB_DATABASE');

    if (! is_string($database) || ! str_ends_with($database, '_test')) {
        throw new RuntimeException('PostgreSQL tests require an explicit database name ending in _test.');
    }

    $_SERVER['DB_CONNECTION'] = 'pgsql';
    $_SERVER['DB_HOST'] = getenv('TEST_DB_HOST') ?: '127.0.0.1';
    $_SERVER['DB_PORT'] = getenv('TEST_DB_PORT') ?: '5432';
    $_SERVER['DB_DATABASE'] = $database;
    $_SERVER['DB_USERNAME'] = getenv('TEST_DB_USERNAME') ?: 'dawtfg_test';
    $_SERVER['DB_PASSWORD'] = getenv('TEST_DB_PASSWORD') ?: '';
}
