# Release Notes

## [Unreleased](https://github.com/zacksmash/outpost/commits/main/compare/v0.6.0...HEAD)

## [v0.6.0](https://github.com/zacksmash/outpost/commits/main/compare/v0.5.6...v0.6.0) - 2026-08-16

### Fixed

- Branch names containing `/`, `_`, or `.` no longer lose those characters when they become instance names. The branch `feature/some-bug-to-fix` now creates `feature-some-bug-to-fix` instead of `featuresome-bug-to-fix`.
- `--name` values are cleaned up and accepted rather than rejected. `--name "Not A Slug"` becomes `not-a-slug` and reports the change.
- `outpost:info` no longer labels two different things "Runtime" — the container runtime and the PHP version are now distinct rows.
- `outpost:info` could silently drop a row when a configured preview endpoint shared a name with a built-in field.
- `outpost:list` no longer reports images as current when their status is actually unknown.

### Changed

- Instance names longer than the DNS label limit are shortened automatically, keeping a readable prefix and appending a short hash of the branch, instead of failing.
- `outpost <branch> --no-interaction` derives the instance name from the branch. Scripts that relied on the previous slug behavior should pass `--name` explicitly.
- `outpost:list` shows four columns — name, branch, state, and URL — with a summary of counts and anything needing attention. The remaining detail lives in `outpost:info`.
- `outpost:doctor` shows troubleshooting guidance only when a check fails or warns, or with `-v`. A healthy run prints the checks and a one-line summary.
- `outpost:doctor` and `outpost:info` wrap within the terminal instead of overflowing.
- Pinned the package and release image together at `ghcr.io/zacksmash/outpost:0.6.0`.

### Unchanged

- Existing instances keep their names, hostnames, and URLs. No migration.
- `--json` output is byte-for-byte identical for every command.

## [v0.5.6](https://github.com/zacksmash/outpost/commits/main/compare/v0.5.5...v0.5.6) - 2026-08-15

### Fixed

- Container stops now retry Apple container's transient stale-exec race, accept a stop that completed despite the runtime error, and replace a persistent raw `internalError` with actionable wait-and-retry guidance.

### Changed

- Pinned the package and release image together at `ghcr.io/zacksmash/outpost:0.5.6`.

## [v0.5.5](https://github.com/zacksmash/outpost/commits/main/compare/v0.5.4...v0.5.5) - 2026-08-15

### Fixed

- The base image now includes Playwright's Ubuntu 24.04 Chromium runtime dependencies, allowing non-root setup hooks to install a project's matching browser without `--with-deps`; Playwright and browser binaries remain project-managed.

### Changed

- Pinned the package and release image together at `ghcr.io/zacksmash/outpost:0.5.5`.

## [v0.5.4](https://github.com/zacksmash/outpost/commits/main/compare/v0.5.3...v0.5.4) - 2026-08-15

### Fixed

- Upgrade, missing-container recovery, and removal now automatically delete the root-level `dump.rdb` artifact only when it is proven to be an untracked Redis snapshot from legacy Outpost runtime configuration; ambiguous files remain protected by the dirty-worktree guard.

### Changed

- Pinned the package and release image together at `ghcr.io/zacksmash/outpost:0.5.4`.

## [v0.5.3](https://github.com/zacksmash/outpost/commits/main/compare/v0.5.2...v0.5.3) - 2026-08-15

### Fixed

- Redis now runs as its dedicated system user with `/var/lib/redis` as its data directory, preventing `dump.rdb` from leaking into and permanently dirtying application worktrees.

### Upgrade note

- Redis instances upgraded from v0.5.2 or earlier may leave one root-level, untracked `dump.rdb` in the worktree when the old container shuts down. This is disposable instance Redis state; delete it before rerunning `outpost:upgrade` or `outpost:remove`.

### Changed

- Pinned the package and release image together at `ghcr.io/zacksmash/outpost:0.5.3`.

## [v0.5.2](https://github.com/zacksmash/outpost/commits/main/compare/v0.5.1...v0.5.2) - 2026-08-15

### Fixed

- Normal upgrades and missing-container recovery now migrate legacy instances whose single managed MySQL or PostgreSQL service was provisioned beside an unrelated application database. `outpost:upgrade` detects and rebuilds this mismatch even when the instance already uses the configured image.
- Doctor now warns when `outpost.image` or `OUTPOST_IMAGE` pins a different official release image than the installed package ships, while continuing to accept intentional compatible custom images.

### Changed

- Pinned the package and release image together at `ghcr.io/zacksmash/outpost:0.5.2`.

## [v0.5.1](https://github.com/zacksmash/outpost/commits/main/compare/v0.5.0...v0.5.1) - 2026-08-15

### Fixed

- An explicit `services` override containing one database now makes that service the application's instance connection and reconciles the complete `DB_*` environment block instead of provisioning an unused database beside SQLite.
- Doctor and the bundled agent skill now clarify that service web endpoints such as Mailpit use the application's HTTP or HTTPS scheme and point to the exact URLs from `outpost:info`.

### Changed

- Pinned the package and release image together at `ghcr.io/zacksmash/outpost:0.5.1`.

## [v0.5.0](https://github.com/zacksmash/outpost/commits/main/compare/v0.4.0...v0.5.0) - 2026-08-15

### Added

- Added `outpost:upgrade --force` to rebuild current-image containers from the latest HTTPS, PHP, resource, service, process, and frontend configuration while preserving the existing worktree and branch.

### Fixed

- `outpost:install --https` now persists `OUTPOST_HTTPS=true` in the host application's `.env` before preparing certificates, so the option actually enables HTTPS for new instances. Doctor warns when trusted HTTPS from an earlier installation is prepared but disabled and prints the exact enable command.
- Forced configuration rebuilds reconcile Outpost-managed `.env` keys, removing stale database, Redis, or Mailpit values when those services or exposure settings are disabled.
- Container upgrades and missing-container recovery now persist and reuse each instance's previously approved Composer path-repository mounts. Only newly discovered repositories require confirmation or `--mount-path-repos`; legacy instances recover prior approval from their safe host bridge.

### Changed

- Human-readable instance output now distinguishes the managed PHP-FPM web runtime from optional configured application processes.
- Pinned the package and release image together at `ghcr.io/zacksmash/outpost:0.5.0`.

## [v0.4.0](https://github.com/zacksmash/outpost/commits/main/compare/v0.3.0...v0.4.0) - 2026-08-15

### Added

- Added `outpost:doctor --json` for a stable, machine-readable readiness report.

### Changed

- Refined every command description, option help, status message, and detail view for a consistent Laravel-first console experience.
- JSON commands now avoid interactive prompts, require explicit instance names, and return a top-level `error` document when a report cannot be produced.
- Pinned the package and release image together at `ghcr.io/zacksmash/outpost:0.4.0`.

## [v0.3.0](https://github.com/zacksmash/outpost/commits/main/compare/v0.2.1...v0.3.0) - 2026-08-15

### Added

- Added `php artisan outpost:verify <name>` with a stable `--json` report for runtime, container, image, Git, production assets, configured shell-free project checks, and the served application response.
- Added named `outpost.checks` commands for project-specific handoff verification, including `@php` resolution to the instance's selected PHP version.
- Added host-owned `setup`, `verify`, and `teardown` lifecycle hooks for repository-specific commands without allowing instance branches to inject executable configuration.
- Added `php artisan outpost:process <name> [process]` with `--restart` and stable `--json` output for inspecting and restarting configured application processes. `outpost:info --json` now includes their live, waiting, or unavailable states.
- Added named, same-origin `outpost.previews` review links with optional notes. They resolve per instance through `outpost:info` and open through the existing `outpost:open <name> <endpoint>` command.
- Added repository-scoped Composer and npm download caches shared by new and rebuilt instances without sharing `vendor` or `node_modules` between worktrees.

### Fixed

- `outpost:remove` now runs teardown hooks only for ready, running instances, checks worktree safety before hook execution without rejecting hook-generated cleanup afterward, deletes stopped containers directly, and keeps `--forget` usable even when hook configuration is invalid.
- `outpost:verify` now skips its production-assets check for API-only applications without a `package.json` build script, matching provisioning behavior, and routes verification hooks through the shared lifecycle runner.
- `outpost:info` now isolates failed Supervisor reads as an `unknown` state for the affected process, preserves credentials and endpoints, and avoids process probes entirely for table output.
- Invalid custom preview entries no longer hide the application endpoint, other valid previews, or all of `outpost:info`; explicitly opening an invalid preview still reports its configuration error.
- Process restart now treats Supervisor `ERROR` output as a failure even when `supervisorctl` exits successfully.
- Boolean `.env` values such as `OUTPOST_HTTPS=1` or `OUTPOST_EXPOSE_SERVICES=0` are now coerced instead of crashing commands with a raw stack trace.
- A DNS-cache flush failure no longer marks a fully provisioned instance as failed; it warns and prints the manual flush command instead.
- Instance creation now refuses container names that exceed the 63-character DNS label limit or cannot round-trip as a slug, instead of minting an instance whose URL never resolves or that later commands refuse to load.
- `outpost:remove` reports a clean error for non-slug names and for worktrees whose git linkage is broken, instead of crashing inside its own recovery path.
- `outpost:install` restarts the container system even when writing the publication domain fails, requires the explicit `--force` option in non-interactive runs instead of auto-approving the setup plan, and reports configuration errors from its planning phase as friendly failures.
- Git operations and in-container provisioning commands no longer inherit process timeouts (60s and 600s respectively), so large fetches and cold composer/npm installs are not killed mid-flight, matching the documented timeout behavior.
- The readiness probe passes `--max-time` to curl, so a hung application respects `outpost.timeout` instead of blocking for minutes.
- `outpost:start` refuses to rebuild over a dirty worktree — matching `outpost:upgrade` — and clears a stale `failed` status once the instance answers HTTP again.
- `outpost:logs` fails cleanly when the container is missing instead of exiting successfully in `--follow` mode.
- `outpost:stop` treats an already-stopped or missing container as a no-op instead of surfacing a raw runtime error.
- The `outpost.services` override and configured `outpost.php` versions are validated up front, so a typo is named immediately instead of failing provisioning with a connection error.
- Manifest `php` and `url` values are validated when read, so a hand-edited manifest can no longer inject content into generated nginx, supervisord, or `.env` files.
- The publication-domain writer recognizes `[ dns ]` headers and `[[table]]` boundaries in `config.toml`, so hand-edited files are no longer corrupted.
- SQLite instances write `DB_DATABASE` alongside `DB_CONNECTION`, so an `.env.example` carrying a MySQL database name no longer breaks migrations.
- Instance names that would collide with the trusted HTTPS state directory are reserved, and a custom `OUTPOST_PATH` no longer leaves `.outpost/tls` out of `.gitignore`.
- Manifests are written atomically, so concurrent commands can never read a torn manifest.
- The base image removes Ubuntu's stock UID-1000 user, so hosts whose account maps to UID 1000 can boot instances.

### Changed

- Command parsing and combined process output now use shared implementations across managed processes, lifecycle hooks, verification checks, provisioning, and runtime operations.
- External `RuntimeDriver` implementations must add `processStates()` and `restartProcess()`, and accept the optional `environment` argument on `boot()`. This is a breaking contract change for custom drivers; Apple container remains the only supported driver.
- The package and release image are pinned together at `ghcr.io/zacksmash/outpost:0.3.0`.
- Console commands are registered lazily via `#[AsCommand]`, so applications no longer construct all eighteen Outpost commands on every artisan invocation.
- The image compatibility contract is now enforced by one shared check across doctor, instance creation, and container rebuilds.
- Removed the deprecated, inert `--recreate` option from `outpost:start`.
- Removed the undocumented `outpost` publish tag; configuration publishes via `outpost-config`.

## [v0.2.1](https://github.com/zacksmash/outpost/commits/main/compare/v0.2.0...v0.2.1) - 2026-08-15

### Added

- Added a `RuntimeDriver` contract, bound to the existing Apple container implementation, so lifecycle consumers can remain engine-neutral without exposing unsupported runtime selection.
- Instance manifests and agent-readable info now record the stable `apple-container` driver identifier. Legacy manifests default to that identifier because earlier Outpost releases supported only Apple container.

## [v0.2.0](https://github.com/zacksmash/outpost/commits/main/compare/v0.1.2...v0.2.0) - 2026-08-15

Outpost is now deliberately an isolated Laravel branch environment for parallel agents and reviewers—not a replacement for the primary development environment. Instances use predictable PHP-FPM and production-built front-end assets so the browser preview reflects what is actually going to ship.

### Breaking changes

- Removed automatic Octane runtime mirroring, Swoole, RoadRunner, FrankenPHP, the polling watcher, and `outpost:reload`.
- Removed managed Vite/HMR mode. Outpost builds production assets once; agents must rebuild after front-end changes before previewing or handing off work.
- Trusted HTTPS is now an explicit `OUTPOST_HTTPS=true` opt-in. HTTP is the default.
- Removed detected-but-deferred capability reporting. Outpost reports only services and processes it actually runs.

### Added

- Added `php artisan outpost:upgrade <name>` and `outpost:upgrade --all` to replace outdated or missing containers while preserving clean worktrees and branches.
- Instance manifests now record the OCI image reference and immutable digest used to create each container.
- `outpost:list` and `outpost:info --json` expose image identities and a tri-state `outdated` flag.

### Changed

- Missing containers are recreated automatically by `outpost:start`.
- Recovery and upgrades regenerate Outpost-owned runtime configuration, preserve the application key, and refresh Composer dependencies, front-end builds, and migrations.
- Doctor requires both the runtime-path contract and an immutable image digest before reporting the configured image ready.
- The package and release image are pinned together at `ghcr.io/zacksmash/outpost:0.2.0`.

### Upgrade

```bash
composer update zacksmash/outpost --with-all-dependencies
php artisan outpost:upgrade --all



```
Upgrade refuses dirty worktrees and keeps source and branches. Replacing a container resets its container-local database and service data.

## [v0.1.2](https://github.com/zacksmash/outpost/commits/main/compare/v0.1.1...v0.1.2) - 2026-08-15

### Added

- Added `php artisan outpost:start <name> --recreate` to rebuild a missing container around its surviving worktree, manifest, runtime configuration, HTTPS files, and approved Composer path repositories.
- Recovery preserves the existing environment and application key, reinstalls Composer dependencies, reruns migrations, and pulls the exact image when it is absent.

### Changed

- `outpost:info --json` and `outpost:list --json` now report `status: degraded` when the live container state is `missing`.
- The package now defaults to `ghcr.io/zacksmash/outpost:0.1.2`.

Container-local MySQL, PostgreSQL, Redis, and Mailpit data cannot be recovered after the container disappears; SQLite data stored in the worktree survives.

## [v0.1.1](https://github.com/zacksmash/outpost/commits/main/compare/v0.1.0...v0.1.1) - 2026-08-15

### Added

- Added `php artisan outpost:reload <name>` for explicitly reloading Octane workers in a running instance.

### Changed

- Octane instances now use an Outpost-managed polling watcher, so PHP edits reload Swoole, RoadRunner, and FrankenPHP workers reliably across host bind mounts.
- Internal application probes now document the correct HTTP and HTTPS localhost commands.
- The README is 58% shorter while retaining installation, runtime, command, safety, and configuration guidance.
- ARM64 release images now build on native GitHub ARM hardware instead of QEMU and retain the BuildKit cache.
- The package now defaults to the exact `ghcr.io/zacksmash/outpost:0.1.1` image.

## [v0.1.0](https://github.com/zacksmash/outpost/commits/main/compare/main...v0.1.0) - 2026-08-15

### Added

- Versioned ARM64 base-image distribution from `ghcr.io/zacksmash/outpost`, including `outpost:pull`, exact image references, a local `outpost:build` customization fallback, and release-triggered publishing through pinned GitHub Actions. No image is published until a release is explicitly published or the workflow is manually dispatched.
- Remote branch and GitHub pull-request checkouts, `--open` after creation, and `outpost:open` for starting and opening an existing instance without copying URLs by hand. Remote sources are fetched into editable local branches without resetting an existing local branch.
- Configurable application processes for queue workers, the scheduler, Horizon, and other daemons. Commands use shell-free argument arrays with an `@php` version placeholder, wait for successful provisioning before starting under Supervisor, persist across container restarts, stream into instance logs, and are recorded in each manifest.
- Automatic Laravel Octane support through the application's selected Swoole, RoadRunner, or FrankenPHP server and a websocket-aware nginx proxy, including bind-mount-friendly watch mode, an explicit PHP-FPM override, per-instance runtime persistence and reporting, and actionable compatibility checks before creation.
- Configurable front-end workflows: one-time asset builds by default, a supervised Vite development server with HMR and a routable per-instance hot URL, or no Node setup at all.
- Trusted local HTTPS via `outpost:certify` and `mkcert`, with automatic primary-application scheme detection (including trusted Herd or Valet listeners behind a stale HTTP `APP_URL`), explicit HTTPS and HTTP overrides, one-time local authority setup, exact per-instance hostname certificates without wildcard matching, automatic HTTP fallback, domain-change protection, nginx redirects and TLS termination for the application, Vite HMR, and Mailpit.
- Direct host access to MySQL, PostgreSQL, Redis, Mailpit SMTP, and the Mailpit UI on each instance's hostname and standard ports, with authenticated stateful services, an opt-out for loopback-only services, copyable details from `outpost:info`, structured `--json` output for agents, and named browser endpoints through `outpost:open`.
- `php artisan outpost:install` — one-confirmation host setup that uses doctor checks to start Apple container, preserve and update its user-level publication-domain configuration, restart it, invoke the administrator-protected DNS registration, prepare trusted HTTPS, and pull a missing image; supports non-interactive `--force`, explicit `--https`, and local-image builds via `--local`.
- `php artisan outpost:doctor` — read-only diagnostics for the supported macOS and Apple silicon platform, Apple `container` version and service state, live publication domain, DNS resolver, base image, trusted HTTPS, Git repository, environment template, and Composer lock file, with exact remediation and browser Local Network guidance.
- `php artisan outpost` — create an isolated instance of any branch: automatic interactive first-run setup when the Mac is not ready, prompt-driven branch and name selection, runtime and service detection from the application's own configuration, git worktree checkout, generated nginx and supervisord configuration, container boot, full provisioning (`.env` seeding from `.env.example`, instance database credentials, `composer install`, `key:generate`, `storage:link`, the selected front-end workflow, `migrate`, optional `--seed`), and final HTTP readiness polling. Non-interactive creation reports the explicit setup command instead of attempting privileged changes; generated nginx configuration accommodates modern Laravel preload headers.
- `php artisan outpost:build` — build a customized local base image (Ubuntu 24.04, nginx, PHP 8.4 + 8.5 with Swoole, pinned checksum-verified RoadRunner and FrankenPHP binaries, Node, MySQL, PostgreSQL, Redis, Mailpit, supervisord) with database credentials and PHP versions supplied from configuration as build arguments.
- `php artisan outpost:list`, `outpost:info`, `outpost:open`, `outpost:start`, `outpost:stop`, `outpost:shell`, `outpost:logs`, and `outpost:remove` for day-to-day instance management.
- Scriptable agent operations through `outpost:list --json` and shell-free `outpost:exec`, including streamed output and unchanged command exit codes.
- Predictable per-instance CPU and memory allocation, defaulting to 4 CPUs and 2 GB with configuration validation before any worktree or manifest is created and resource details recorded for `outpost:info`.
- Service detection for MySQL/MariaDB, PostgreSQL, Redis, and Mailpit, with a `config('outpost.services')` override and honest reporting of detected-but-deferred capabilities (Horizon and external Scout drivers).
- Per-instance manifest at `.outpost/<name>/outpost.json` recording what was detected and provisioned.
- Read-only mounting of composer path repositories behind an explicit default-no confirmation; non-interactive runs mount nothing unless `--mount-path-repos` is passed, and sensitive locations (the home directory, its ancestors, hidden directories directly beneath it, and ~/Library) are never mounted.
- `config/outpost.php` with the instance domain, base image, DNS, instance path and resources, PHP versions, Octane/PHP-FPM selection, Swoole/RoadRunner/FrankenPHP selection, front-end workflow, trusted HTTPS, service detection and exposure, supervised processes, instance credentials, and boot timeout.

### Changed

- The initial `0.1.0` base image records its `/etc/outpost` runtime-path contract as an OCI label. Doctor rejects missing or mismatched contracts before creation and prints forced pull/build repairs, while image publishing stamps the same contract explicitly.
- Front-end provisioning now uses `npm ci` whenever `package-lock.json` exists, keeping new worktrees reproducible and avoiding lock-file noise before development begins.
- Approved Composer path repositories now receive ignored host-side bridge links, allowing Composer's container-relative vendor symlinks to resolve for host Artisan, IDE, Herd, and Boost usage while remaining read-only inside the instance.
- `outpost:install --local` now states when a compatible existing image is being retained and directs intentional rebuilds to `outpost:build --force`.
- Instance provisioning, `outpost:exec`, and `outpost:shell` now run as a host-ID-mapped non-root application user by default, keeping Composer plugins enabled and bind-mounted file ownership correct; `--root` is the explicit elevation path. The image also permits intentional root Composer use without silently disabling plugins.
- Generated runtime configuration moved from `/outpost` to `/etc/outpost`, and relative Composer path repositories now resolve from the primary checkout to their Composer-visible container path. This allows a sibling `../outpost` package to mount read-only at `/outpost` without colliding with Outpost itself.
- The host repository's common Git directory is mounted read-only at its original absolute path so Git-aware tools such as `pint --dirty` work inside an instance without writable access to host refs; documentation now makes host-side commits explicit.
- Provisioning failures now preserve both stdout and stderr, persist a failed lifecycle status, and appear as `degraded` in `outpost:list` and `outpost:info` instead of merely `running`.
- Removal now refuses dirty worktrees even with `--force`; `--discard-changes` is required to destroy uncommitted work. Doctor and usage guidance now cover Local Network permission for host CLI tools, an in-instance curl check, and retained-branch cleanup before recreating from another base.
- Octane instances now route nginx's `/index.php` fallback through the Octane server instead of exposing it as a static download, accept modern Laravel asset-preload headers without a 502 response, and reject arbitrary public PHP files rather than serving their source in either server mode.
- Quick Apple container lifecycle and inventory calls now time out after a configurable 30 seconds instead of appearing frozen for up to ten minutes when a per-container VM stops responding, with cautious runtime restart guidance in the resulting error. `outpost:remove --forget` provides an explicit last-resort cleanup path for the local worktree and manifest without contacting a stuck runtime, while reporting the orphaned container and its later cleanup command.
- Release image publishing now waits for the full package gate, verifies the semantic release matches the package's exact default image tag, and grants registry write access only to the publishing job; pull-request CI also validates Composer metadata strictly.
