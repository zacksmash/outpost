<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Support\Str;
use RuntimeException;
use Zacksmash\Outpost\Contracts\RuntimeDriver;

class Names
{
    /**
     * The number of candidates tried before falling back to a numeric suffix.
     */
    public const int ATTEMPTS = 50;

    /**
     * The adjectives a generated name may start with.
     *
     * @var list<string>
     */
    protected array $adjectives;

    /**
     * The nouns a generated name may end with.
     *
     * @var list<string>
     */
    protected array $nouns;

    /**
     * Create a new name generator.
     *
     * @param  list<string>|null  $adjectives
     * @param  list<string>|null  $nouns
     */
    public function __construct(?array $adjectives = null, ?array $nouns = null)
    {
        /** @var list<string> $defaultAdjectives */
        $defaultAdjectives = require __DIR__.'/../resources/names/adjectives.php';

        /** @var list<string> $defaultNouns */
        $defaultNouns = require __DIR__.'/../resources/names/nouns.php';

        $this->adjectives = $adjectives ?? $defaultAdjectives;
        $this->nouns = $nouns ?? $defaultNouns;
    }

    /**
     * Generate a readable adjective and noun instance name.
     */
    public function generate(): string
    {
        return $this->word($this->adjectives).'-'.$this->word($this->nouns);
    }

    /**
     * Generate a name no manifest and no container on this machine has claimed.
     *
     * Container names are machine-wide rather than scoped to one application,
     * so an unclaimed name must clear both the local manifests and the runtime.
     */
    public function unique(Outposts $outposts, RuntimeDriver $runtime): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $name = $this->generate();

            if (! $this->taken($name, $outposts, $runtime)) {
                return $name;
            }
        }

        $base = $this->generate();

        for ($suffix = 2; $suffix <= self::ATTEMPTS; $suffix++) {
            if (! $this->taken("{$base}-{$suffix}", $outposts, $runtime)) {
                return "{$base}-{$suffix}";
            }
        }

        throw new RuntimeException('Unable to generate an unused instance name. Remove an instance, or choose one with --name.');
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
     * Determine whether anything on this machine already uses the given name.
     */
    protected function taken(string $name, Outposts $outposts, RuntimeDriver $runtime): bool
    {
        return $outposts->exists($name) || $runtime->exists($name);
    }

    /**
     * Draw one word from the given list.
     *
     * @param  list<string>  $words
     */
    protected function word(array $words): string
    {
        return $words[random_int(0, count($words) - 1)];
    }
}
