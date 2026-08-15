<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;

class VerificationChecks
{
    /**
     * Create a project verification-check reader.
     */
    public function __construct(
        protected readonly Repository $config,
        protected readonly CommandConfiguration $commands,
    ) {}

    /**
     * Resolve every configured check for an instance PHP version.
     *
     * @return array<string, list<string>>
     */
    public function commands(string $php): array
    {
        return $this->commands->resolve(
            $this->config->get('outpost.checks', []),
            'outpost.checks',
            'check',
            $php,
        );
    }
}
