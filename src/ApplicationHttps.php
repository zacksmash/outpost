<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Facades\Process;

class ApplicationHttps
{
    protected ?bool $secure = null;

    /**
     * Create a detector for the primary Laravel application's URL scheme.
     */
    public function __construct(
        protected readonly UrlGenerator $urls,
    ) {}

    /**
     * Determine whether the primary application is served over trusted HTTPS.
     */
    public function detected(): bool
    {
        if ($this->secure !== null) {
            return $this->secure;
        }

        $url = $this->urls->to('/');
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return $this->secure = false;
        }

        if (strcasecmp($scheme, 'https') === 0) {
            return $this->secure = true;
        }

        if (strcasecmp($scheme, 'http') !== 0) {
            return $this->secure = false;
        }

        $httpsUrl = preg_replace('/^http:/i', 'https:', $url, 1);

        if (! is_string($httpsUrl)) {
            return $this->secure = false;
        }

        return $this->secure = Process::timeout(2)->run([
            'curl',
            '--head',
            '--silent',
            '--show-error',
            '--output', '/dev/null',
            '--connect-timeout', '1',
            '--max-time', '2',
            $httpsUrl,
        ])->successful();
    }
}
