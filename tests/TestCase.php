<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zacksmash\Outpost\OutpostServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            OutpostServiceProvider::class,
        ];
    }
}
