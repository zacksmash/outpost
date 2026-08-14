<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), or exit()')
    ->expect(['dd', 'ddd', 'exit'])
    ->each->not->toBeUsed();

arch('env() stays out of the package source')
    ->expect('Zacksmash\Outpost')
    ->not->toUse(['env']);

arch('the package source declares strict types')
    ->expect('Zacksmash\Outpost')
    ->toUseStrictTypes();
