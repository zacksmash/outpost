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
     * Determine whether mkcert is available on this Mac.
     */
    public function available(): bool
    {
        return Process::run(['mkcert', '-version'])->successful();
    }

    /**
     * Determine whether new instances should prefer HTTPS.
     */
    public function wantsHttps(): bool
    {
        $enabled = $this->config->get('outpost.https', false);

        if (! is_bool($enabled)) {
            throw new RuntimeException('The [outpost.https] value must be true or false.');
        }

        return $enabled;
    }

    /**
     * Determine whether new instances should use HTTPS.
     */
    public function enabled(): bool
    {
        $this->validateDomain($this->configuredDomain());

        if (! $this->wantsHttps()) {
            return false;
        }

        if ($this->exists()) {
            return true;
        }

        throw new RuntimeException(
            'HTTPS is enabled, but trusted HTTPS has not been prepared. Run [php artisan outpost:certify].',
        );
    }

    /**
     * Determine whether trusted HTTPS is prepared for the configured domain.
     */
    public function exists(): bool
    {
        if (! $this->files->isFile($this->domainPath())
            || trim($this->files->get($this->domainPath())) !== $this->configuredDomain()) {
            return false;
        }

        return $this->files->isFile($this->trustedPath())
            || ($this->files->isFile($this->certificatePath())
                && $this->files->isFile($this->keyPath()));
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
     * Get the legacy shared public certificate path.
     */
    public function certificatePath(): string
    {
        return $this->directory().'/certificate.pem';
    }

    /**
     * Get the legacy shared private key path.
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
     * Get the marker written after the local authority is installed.
     */
    public function trustedPath(): string
    {
        return $this->directory().'/trusted';
    }

    /**
     * Install the local certificate authority and record the trusted domain.
     */
    public function create(string $domain, ?callable $output = null): void
    {
        $this->validateDomain($domain);

        if (! $this->available()) {
            throw new RuntimeException(
                'Outpost uses mkcert for trusted local HTTPS. Install it first with [brew install mkcert].',
            );
        }

        $install = Process::forever()
            ->tty(SymfonyProcess::isTtySupported())
            ->run(['mkcert', '-install'], $output);

        if (! $install->successful()) {
            // A TTY run writes straight to the terminal, so there may be no
            // captured output to relay.
            $output = trim($install->errorOutput() ?: $install->output());

            throw new RuntimeException(
                'Unable to install the local mkcert authority'.($output === '' ? '.' : ": {$output}"),
            );
        }

        $this->files->ensureDirectoryExists($this->directory(), 0700);

        if ($this->files->put($this->domainPath(), $domain."\n") === false) {
            throw new RuntimeException('Unable to record the Outpost HTTPS certificate domain.');
        }

        if ($this->files->put($this->trustedPath(), "mkcert\n") === false) {
            throw new RuntimeException('Unable to record the trusted Outpost HTTPS setup.');
        }
    }

    /**
     * Create a leaf certificate for one exact instance hostname.
     */
    public function createForHost(string $hostname, string $directory, ?callable $output = null): void
    {
        $domain = $this->configuredDomain();

        $this->validateDomain($hostname);

        if ($hostname === $domain || ! str_ends_with($hostname, ".{$domain}")) {
            throw new RuntimeException(
                "The certificate name [{$hostname}] must be an exact hostname beneath [{$domain}].",
            );
        }

        if (! $this->exists()) {
            throw new RuntimeException(
                'Trusted HTTPS is not prepared. Run [php artisan outpost:certify].',
            );
        }

        $directory = $this->validatedDirectory($directory);
        $this->files->ensureDirectoryExists($directory, 0700);

        $certificatePath = $directory.'/certificate.pem';
        $keyPath = $directory.'/key.pem';

        $certificate = Process::forever()->run([
            'mkcert',
            '-cert-file', $certificatePath,
            '-key-file', $keyPath,
            $hostname,
        ], $output);

        if (! $certificate->successful()) {
            throw new RuntimeException(
                "Unable to create the HTTPS certificate for [{$hostname}]: ".trim($certificate->errorOutput() ?: $certificate->output()),
            );
        }

        if ($this->files->exists($keyPath)) {
            $this->files->chmod($keyPath, 0600);
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

    /**
     * Refuse an unsafe certificate output directory.
     */
    protected function validatedDirectory(string $directory): string
    {
        $directory = rtrim($directory, '/');

        if ($directory === ''
            || $directory === '.'
            || $directory === '..'
            || str_contains($directory, "\0")
            || str_contains($directory, "\n")
            || str_contains($directory, "\r")) {
            throw new RuntimeException('The instance certificate directory is not valid.');
        }

        return $directory;
    }
}
