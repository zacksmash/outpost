<?php

declare(strict_types=1);

namespace Outpost\Outpost\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Outpost\Outpost\OutpostServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            OutpostServiceProvider::class,
        ];
    }
}
