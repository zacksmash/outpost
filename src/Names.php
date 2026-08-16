<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Support\Str;
use RuntimeException;

class Names
{
    /**
     * The number of numeric suffixes tried before giving up.
     */
    public const int ATTEMPTS = 50;

    /**
     * The number of hexadecimal characters kept from the disambiguating hash.
     */
    protected const int HASH_LENGTH = 4;

    /**
     * Derive an instance name from a branch, within the given budget.
     *
     * The normalized branch is used unchanged when it fits the budget — no
     * hash, no truncation. When it does not fit, it is cut back to the last
     * hyphen at or before the available space so the result never ends
     * mid-word, then suffixed with a few characters of a hash of the full
     * original branch. The hash is what keeps the result deterministic (the
     * same branch always derives the same name, which is what makes
     * --no-interaction predictable for CI) while stopping two long branches
     * that share a prefix from truncating to the same name.
     */
    public function derive(string $branch, int $budget): string
    {
        $normalized = $this->normalize($branch);

        if (strlen($normalized) <= $budget) {
            return $normalized;
        }

        $hash = substr(hash('xxh128', $branch), 0, self::HASH_LENGTH);
        $available = max($budget - self::HASH_LENGTH - 1, 0);
        $truncated = substr($normalized, 0, $available);

        $lastHyphen = strrpos($truncated, '-');

        if ($lastHyphen !== false) {
            $truncated = substr($truncated, 0, $lastHyphen);
        }

        return "{$truncated}-{$hash}";
    }

    /**
     * Find a name based on the given candidate that no manifest in this
     * application has claimed, appending a numeric suffix on collision.
     *
     * The candidate only has to be unique within this application: the
     * project-directory suffix appended to the container name keeps
     * container names scoped per project, so two applications deriving the
     * same instance name cannot collide. A numeric suffix that would push an
     * already budget-filling candidate past the limit trims the base first,
     * so the result never exceeds the budget even on collision.
     */
    public function unique(Outposts $outposts, string $name, int $budget): string
    {
        if (! $outposts->exists($name)) {
            return $name;
        }

        for ($number = 2; $number <= self::ATTEMPTS; $number++) {
            $candidate = $this->withSuffix($name, $number, $budget);

            if (! $outposts->exists($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to find an available instance name after '.self::ATTEMPTS.' attempts. Remove an instance, or choose one with --name.');
    }

    /**
     * Reduce a supplied name to a URL-friendly slug.
     *
     * Str::slug() drops characters like [/] instead of separating on them,
     * which would turn [feature/billing] into [featurebilling], so every run
     * of non-alphanumeric characters becomes a single hyphen first.
     */
    public function normalize(string $name): string
    {
        return Str::slug((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', $name));
    }

    /**
     * Append a numeric suffix to a name, trimming the base first if the
     * suffix would otherwise push the result past the budget.
     */
    protected function withSuffix(string $name, int $number, int $budget): string
    {
        $suffix = "-{$number}";

        if (strlen($name) + strlen($suffix) <= $budget) {
            return $name.$suffix;
        }

        return substr($name, 0, max($budget - strlen($suffix), 0)).$suffix;
    }
}
