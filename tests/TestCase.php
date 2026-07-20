<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $isInMemoryDatabase = $connection === 'sqlite' && $database === ':memory:';

        if (! $app->environment('testing')) {
            throw new RuntimeException('The test suite must run with APP_ENV=testing.');
        }

        if (! $isInMemoryDatabase && ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Unsafe test database [{$database}]. Its name must end with [_test].",
            );
        }

        return $app;
    }
}
