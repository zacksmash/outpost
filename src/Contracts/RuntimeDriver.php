<?php

declare(strict_types=1);

namespace Zacksmash\Outpost\Contracts;

use Illuminate\Contracts\Process\ProcessResult;
use Zacksmash\Outpost\Manifest;

/**
 * Container-engine operations required by Outpost's existing lifecycle.
 */
interface RuntimeDriver
{
    /**
     * Get the stable lowercase identifier stored in instance manifests.
     */
    public function id(): string;

    public function version(): string;

    public function systemStatus(): string;

    public function startSystem(): void;

    public function stopSystem(): void;

    public function publicationDomain(): ?string;

    public function domainRegistered(string $domain): bool;

    public function registerDomain(string $domain): void;

    public function hasImage(string $image): bool;

    /**
     * @return array{digest: string|null, labels: array<string, string>}|null
     */
    public function imageMetadata(string $image): ?array;

    public function pull(string $image, ?callable $output = null): void;

    /**
     * @param  array<string, string>  $buildArgs
     */
    public function build(
        string $image,
        string $dns,
        string $context,
        array $buildArgs = [],
        ?callable $output = null,
    ): void;

    /**
     * @param  list<string>  $volumes
     * @param  array<string, string>  $environment
     */
    public function boot(
        string $container,
        string $image,
        string $dns,
        array $volumes,
        int $cpus = 4,
        string $memory = '2G',
        ?int $uid = null,
        ?int $gid = null,
        array $environment = [],
    ): void;

    /**
     * @return array{cpus: int, memory: string}
     */
    public function validatedResources(mixed $cpus, mixed $memory): array;

    public function start(string $container): void;

    public function releaseProcesses(string $container): void;

    /**
     * Read the state of named application processes managed by the runtime.
     *
     * @param  list<string>  $processes
     * @return array<string, array{state: string, details: string}>
     */
    public function processStates(string $container, array $processes): array;

    public function restartProcess(string $container, string $process): void;

    public function stop(string $container): void;

    public function delete(string $container, bool $force = false): void;

    /**
     * @param  list<string>  $command
     */
    public function exec(string $container, array $command, bool $root = false): ProcessResult;

    /**
     * @param  list<string>  $command
     */
    public function run(string $container, array $command, ?callable $output = null, bool $root = false): int;

    /**
     * @return array<string, string>
     */
    public function states(): array;

    public function state(string $container): ?string;

    public function exists(string $container): bool;

    public function running(string $container): bool;

    public function instanceState(Manifest $manifest, ?string $runtimeState = null): string;

    public function instanceStatus(Manifest $manifest, ?string $runtimeState = null): string;

    public function ready(string $container, bool $secure = false): bool;

    public function awaitReady(string $container, int $seconds, bool $secure = false): bool;

    public function shell(string $container, ?callable $output = null, bool $root = false): int;

    public function logs(string $container, bool $follow = false, ?callable $output = null): void;

    public function flushDnsCache(): void;
}
