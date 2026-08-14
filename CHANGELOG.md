# Release Notes

## [Unreleased](https://github.com/zacksmash/outpost/commits/main)

### Added

- Versioned ARM64 base-image distribution from `ghcr.io/zacksmash/outpost`, including `outpost:pull`, exact image references, a local `outpost:build` customization fallback, and release-triggered publishing through pinned GitHub Actions. No image is published until a release is explicitly published or the workflow is manually dispatched.
- Remote branch and GitHub pull-request checkouts, `--open` after creation, and `outpost:open` for starting and opening an existing instance without copying URLs by hand. Remote sources are fetched into editable local branches without resetting an existing local branch.
- Configurable application processes for queue workers, the scheduler, Horizon, and other daemons. Commands use shell-free argument arrays with an `@php` version placeholder, wait for successful provisioning before starting under Supervisor, persist across container restarts, stream into instance logs, and are recorded in each manifest.
- Automatic Laravel Octane support through Swoole and a websocket-aware nginx proxy, including bind-mount-friendly watch mode, an explicit PHP-FPM override, and per-instance server reporting.
- Configurable front-end workflows: one-time asset builds by default, a supervised Vite development server with HMR and a routable per-instance hot URL, or no Node setup at all.
- Trusted local HTTPS via `outpost:certify` and `mkcert`, with wildcard certificate reuse, automatic HTTP fallback, domain-change protection, nginx redirects and TLS termination for the application, Vite HMR, and Mailpit.
- Direct host access to MySQL, PostgreSQL, Redis, Mailpit SMTP, and the Mailpit UI on each instance's hostname and standard ports, with authenticated stateful services, an opt-out for loopback-only services, copyable details from `outpost:info`, structured `--json` output for agents, and named browser endpoints through `outpost:open`.
- `php artisan outpost:install` — guided one-time setup that uses the doctor checks, offers to start the Apple container service, pull a missing image, and create trusted HTTPS, supports non-interactive `--force`, explicit `--https`, and local-image builds via `--local`, and leaves privileged or configuration changes as explicit remedies.
- `php artisan outpost:doctor` — read-only diagnostics for the supported macOS and Apple silicon platform, Apple `container` version and service state, live publication domain, DNS resolver, base image, trusted HTTPS, Git repository, environment template, and Composer lock file, with exact remediation and browser Local Network guidance.
- `php artisan outpost` — create an isolated instance of any branch: prompt-driven branch and name selection, runtime and service detection from the application's own configuration, git worktree checkout, generated nginx and supervisord configuration, container boot, full provisioning (`.env` seeding from `.env.example`, sandbox database credentials, `composer install`, `key:generate`, `storage:link`, the selected front-end workflow, `migrate`, optional `--seed`), and final HTTP readiness polling. Creation verifies the running service's DNS publication domain matches the configured instance domain and explains the restart needed when it doesn't; generated nginx configuration also accommodates modern Laravel preload headers.
- `php artisan outpost:build` — build a customized local base image (Ubuntu 24.04, nginx, PHP 8.4 + 8.5 with Swoole, Node, MySQL, PostgreSQL, Redis, Mailpit, supervisord) with database credentials and PHP versions supplied from configuration as build arguments.
- `php artisan outpost:list`, `outpost:info`, `outpost:open`, `outpost:start`, `outpost:stop`, `outpost:shell`, `outpost:logs`, and `outpost:remove` for day-to-day instance management.
- Scriptable agent operations through `outpost:list --json` and shell-free `outpost:exec`, including streamed output and unchanged command exit codes.
- Predictable per-instance CPU and memory allocation, defaulting to 4 CPUs and 2 GB with configuration validation before any worktree or manifest is created and resource details recorded for `outpost:info`.
- Service detection for MySQL/MariaDB, PostgreSQL, Redis, and Mailpit, with a `config('outpost.services')` override and honest reporting of detected-but-deferred capabilities (Horizon and external Scout drivers).
- Per-instance manifest at `.outpost/<name>/outpost.json` recording what was detected and provisioned.
- Read-only mounting of composer path repositories behind an explicit default-no confirmation; non-interactive runs mount nothing unless `--mount-path-repos` is passed, and sensitive locations (the home directory, its ancestors, hidden directories directly beneath it, and ~/Library) are never mounted.
- `config/outpost.php` with the instance domain, base image, DNS, instance path and resources, PHP versions, Octane/PHP-FPM selection, front-end workflow, trusted HTTPS, service detection and exposure, supervised processes, sandbox credentials, and boot timeout.

### Changed

- Release image publishing now waits for the full package gate, verifies the semantic release matches the package's exact default image tag, and grants registry write access only to the publishing job; pull-request CI also validates Composer metadata strictly.
