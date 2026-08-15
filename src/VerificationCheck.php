<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

class VerificationCheck
{
    public const string PASS = 'PASS';

    public const string WARNING = 'WARN';

    public const string FAIL = 'FAIL';

    public const string SKIP = 'SKIP';

    /**
     * @param  list<string>|null  $command
     */
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $detail,
        public readonly ?array $command = null,
        public readonly ?int $exitCode = null,
    ) {}

    /**
     * @return array{name: string, status: string, detail: string, command: list<string>|null, exit_code: int|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'detail' => $this->detail,
            'command' => $this->command,
            'exit_code' => $this->exitCode,
        ];
    }
}
