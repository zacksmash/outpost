<?php

declare(strict_types=1);

namespace Zacksmash\Outpost;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;

class Certificates
{
    /**
     * Create a local TLS certificate manager.
     */
    public function __construct(
        protected readonly Filesystem $files,
        protected readonly Repository $config,
        protected readonly string $basePath,
    ) {}

    /**
     * Determine whether new instances should use HTTPS.
     */
    public function enabled(): bool
    {
        $this->validateDomain($this->configuredDomain());
        $mode = $this->config->get('outpost.https', 'auto');

        if ($mode === false) {
            return false;
        }

        if ($mode === 'auto') {
            return $this->exists();
        }

        if ($mode !== true) {
            throw new RuntimeException('The [outpost.https] value must be true, false, or auto.');
        }

        if (! $this->exists()) {
            throw new RuntimeException(
                'HTTPS is enabled, but its certificate files are missing. Run [php artisan outpost:certify].',
            );
        }

        return true;
    }

    /**
     * Determine whether both local TLS files exist.
     */
    public function exists(): bool
    {
        return $this->files->isFile($this->certificatePath())
            && $this->files->isFile($this->keyPath())
            && $this->files->isFile($this->domainPath())
            && trim($this->files->get($this->domainPath())) === $this->configuredDomain();
    }

    /**
     * Get the absolute directory containing the local TLS files.
     */
    public function directory(): string
    {
        $path = $this->config->get('outpost.tls.path', '.outpost/tls');

        if (! is_string($path)
            || $path === ''
            || str_contains($path, "\0")
            || str_contains($path, "\n")
            || str_contains($path, "\r")) {
            throw new RuntimeException('The [outpost.tls.path] value must be a non-empty filesystem path.');
        }

        $path = rtrim($path, '/');

        if ($path === '') {
            throw new RuntimeException('The [outpost.tls.path] value cannot be the filesystem root.');
        }

        return str_starts_with($path, '/') ? $path : $this->basePath.'/'.$path;
    }

    /**
     * Get the public certificate path.
     */
    public function certificatePath(): string
    {
        return $this->directory().'/certificate.pem';
    }

    /**
     * Get the private key path.
     */
    public function keyPath(): string
    {
        return $this->directory().'/key.pem';
    }

    /**
     * Get the certificate domain marker path.
     */
    public function domainPath(): string
    {
        return $this->directory().'/domain';
    }

    /**
     * Create and trust a certificate for the Outpost domain.
     */
    public function create(string $domain, ?callable $output = null): void
    {
        $this->validateDomain($domain);

        $version = Process::run(['mkcert', '-version']);

        if (! $version->successful()) {
            throw new RuntimeException(
                'Outpost uses mkcert for trusted local HTTPS. Install it first with [brew install mkcert].',
            );
        }

        $install = Process::forever()
            ->tty(SymfonyProcess::isTtySupported())
            ->run(['mkcert', '-install'], $output);

        if (! $install->successful()) {
            throw new RuntimeException(
                'Unable to install the local mkcert authority: '.trim($install->errorOutput() ?: $install->output()),
            );
        }

        $this->files->ensureDirectoryExists($this->directory(), 0700);

        $certificate = Process::forever()->run([
            'mkcert',
            '-cert-file', $this->certificatePath(),
            '-key-file', $this->keyPath(),
            $domain,
            "*.{$domain}",
        ], $output);

        if (! $certificate->successful()) {
            throw new RuntimeException(
                'Unable to create the Outpost HTTPS certificate: '.trim($certificate->errorOutput() ?: $certificate->output()),
            );
        }

        if ($this->files->put($this->domainPath(), $domain."\n") === false) {
            throw new RuntimeException('Unable to record the Outpost HTTPS certificate domain.');
        }

        if ($this->files->exists($this->keyPath())) {
            $this->files->chmod($this->keyPath(), 0600);
        }
    }

    /**
     * Read the configured certificate domain.
     */
    protected function configuredDomain(): string
    {
        $domain = $this->config->get('outpost.domain');

        if (! is_string($domain)) {
            throw new RuntimeException('The [outpost.domain] value must be a string.');
        }

        return $domain;
    }

    /**
     * Refuse a domain that cannot safely become a certificate name.
     */
    protected function validateDomain(string $domain): void
    {
        if (preg_match(
            '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/Di',
            $domain,
        ) !== 1) {
            throw new RuntimeException('The [outpost.domain] value is not a valid certificate domain.');
        }
    }
}
