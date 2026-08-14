<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

class Detection
{
    /**
     * Create a new detection result.
     *
     * @param  list<string>  $services
     * @param  list<string>  $deferred
     */
    public function __construct(
        public readonly array $services,
        public readonly array $deferred,
        public readonly ?string $database,
        public readonly string $php,
    ) {}
}
