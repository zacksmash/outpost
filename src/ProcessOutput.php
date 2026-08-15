<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Str;

class ProcessOutput
{
    /**
     * Combine stdout and stderr without allowing either stream to hide the other.
     */
    public static function combined(
        ProcessResult $result,
        ?int $limit = null,
        string $empty = '',
    ): string {
        $output = trim(implode("\n", array_filter([
            trim($result->output()),
            trim($result->errorOutput()),
        ], fn (string $value): bool => $value !== '')));

        if ($output === '') {
            return $empty;
        }

        return $limit === null ? $output : Str::limit($output, $limit);
    }
}
