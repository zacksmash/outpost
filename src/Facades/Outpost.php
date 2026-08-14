<?php

declare(strict_types=1);

namespace Outpost\Outpost\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Outpost\Outpost\Outpost
 */
class Outpost extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Outpost\Outpost\Outpost::class;
    }
}
