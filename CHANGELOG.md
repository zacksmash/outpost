# Release Notes

## [Unreleased](https://github.com/zacksmash/outpost/commits/main)

### Added

- `php artisan outpost:install` — guided one-time setup that uses the doctor checks, offers to start the Apple container service and build a missing image, supports non-interactive `--force`, and leaves privileged or configuration changes as explicit manual remedies.
- `php artisan outpost:doctor` — read-only diagnostics for the supported macOS and Apple silicon platform, Apple `container` version and service state, live publication domain, DNS resolver, base image, Git repository, environment template, and Composer lock file, with exact remediation and browser Local Network guidance.
- `php artisan outpost` — create an isolated instance of any branch: prompt-driven branch and name selection, service detection from the application's own configuration, git worktree checkout, generated nginx and supervisord configuration, container boot, full provisioning (`.env` seeding from `.env.example`, sandbox database credentials, `composer install`, `key:generate`, `storage:link`, `npm install` and a front-end build when the app has a build script, `migrate`, optional `--seed`), and final HTTP readiness polling. Creation verifies the running service's DNS publication domain matches the configured instance domain and explains the restart needed when it doesn't; generated nginx configuration also accommodates modern Laravel preload headers.
- `php artisan outpost:build` — build the shared base image (Ubuntu 24.04, nginx, PHP 8.4 + 8.5, MySQL, PostgreSQL, Redis, Mailpit, supervisord) with database credentials and PHP versions supplied from configuration as build arguments.
- `php artisan outpost:list`, `outpost:start`, `outpost:stop`, `outpost:shell`, `outpost:logs`, and `outpost:remove` for day-to-day instance management.
- Service detection for MySQL/MariaDB, PostgreSQL, Redis, and Mailpit, with a `config('outpost.services')` override and honest reporting of detected-but-deferred capabilities (Octane, Horizon, external Scout drivers).
- Per-instance manifest at `.outpost/<name>/outpost.json` recording what was detected and provisioned.
- Read-only mounting of composer path repositories behind an explicit default-no confirmation; non-interactive runs mount nothing unless `--mount-path-repos` is passed, and sensitive locations (the home directory, its ancestors, hidden directories directly beneath it, and ~/Library) are never mounted.
- `config/outpost.php` with the instance domain, base image, DNS, instance path, PHP versions, service override, sandbox database credentials, and boot timeout.
