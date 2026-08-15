<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

class DoctorCheck
{
    public const string PASS = 'PASS';

    public const string WARNING = 'WARN';

    public const string FAIL = 'FAIL';

    /**
     * Create a new doctor check result.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $detail,
        public readonly ?string $remedy = null,
    ) {}

    /**
     * Create a passing check.
     */
    public static function pass(string $name, string $detail): self
    {
        return new self($name, self::PASS, $detail);
    }

    /**
     * Create a non-blocking warning.
     */
    public static function warning(string $name, string $detail, ?string $remedy = null): self
    {
        return new self($name, self::WARNING, $detail, $remedy);
    }

    /**
     * Create a failed required check.
     */
    public static function failure(string $name, string $detail, string $remedy): self
    {
        return new self($name, self::FAIL, $detail, $remedy);
    }

    /**
     * Serialize the check for command output.
     *
     * @return array{name: string, status: string, detail: string, remedy: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'detail' => $this->detail,
            'remedy' => $this->remedy,
        ];
    }
}
