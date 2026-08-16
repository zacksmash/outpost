---
name: outpost-development
description: >
  Install and operate zacksmash/outpost in Laravel applications to run branches
  as isolated Apple container instances, including safe agent workflows.
license: MIT
metadata:
  author: Zack Warren
---

# Outpost

Use this skill when a Laravel application needs an isolated, editable branch sandbox with its own URL and services on macOS.

## Primary Goal

- use Outpost's public commands to build branches in parallel, preview compiled application output, and preserve, recover, or remove those sandboxes safely

## Workflow

### 1. Confirm and install

Outpost requires macOS 26 or newer on Apple silicon, Apple `container` 1.2.x, and a Laravel Git repository with at least one commit.

```bash
composer require zacksmash/outpost --dev
php artisan outpost:doctor         # --json available
php artisan outpost:install        # optional explicit host setup
```

The first interactive `php artisan outpost` offers required setup before creating an instance. Setup can start Apple container, configure its publication domain, register DNS, and pull the exact image. Use `outpost:install --local` to build a missing image locally; use `outpost:build --force` to intentionally replace an existing local image.

HTTP is the default. For trusted HTTPS, install `mkcert` and run `php artisan outpost:install --https`; the installer persists `OUTPOST_HTTPS=true` in the host application's `.env`, installs the local authority, and gives each new instance an exact-host certificate. Add `--force` for non-interactive setup. Run `outpost:certify` directly only to recreate certificate state without changing the preference.

Doctor is read-only. Apply its `FAIL` remedies before creation. Browsers and host CLI tools may separately need macOS Local Network permission. When host access fails, probe inside the instance:

```bash
php artisan outpost:exec billing -- curl --fail --silent --show-error http://localhost
php artisan outpost:exec billing -- curl --fail --silent --show-error --insecure https://localhost
```

### 2. Create and manage instances

```bash
php artisan outpost feature/billing --name=billing --seed
php artisan outpost origin/review/invoices --name=invoices --open
php artisan outpost --pr=482 --name=pr-482 --open
php artisan outpost:doctor --json
php artisan outpost:list --json
php artisan outpost:info billing --json
php artisan outpost:open billing
php artisan outpost:open billing posts
php artisan outpost:start billing
php artisan outpost:stop billing
php artisan outpost:exec billing -- php artisan test
php artisan outpost:process billing --json
php artisan outpost:process billing queue --restart
php artisan outpost:verify billing --json
php artisan outpost:shell billing
php artisan outpost:logs billing --follow
php artisan outpost:remove billing
```

- `outpost` accepts local, remote, new, or GitHub pull-request branches and creates an editable worktree under `.outpost/<name>/app`.
- New and rebuilt instances reuse repository-local Composer and npm download caches under `.outpost/.cache`; `vendor` and `node_modules` remain private to each worktree.
- Other creation options are `--name`, `--open`, `--seed`, `--remote`, and `--mount-path-repos`.
- Commands with an omitted instance name prompt interactively; the instance-name prompt pre-fills a generated, unclaimed name (for example `blissful-lake`) that you may accept or replace.
- A supplied `--name` is normalized to a URL-friendly slug rather than rejected, so a branch-shaped value like `--name=feature/billing` creates `feature-billing`; pass an explicit `--name` with `--no-interaction` to avoid relying on the generated default. The instance URL is `https://<name>-<project-directory>.<domain>`, scoping the container to the project that created it so identical instance names in different projects never collide.
- `outpost:exec` passes tokens without a shell, streams output, preserves the inner exit code, and runs as the host-ID-mapped non-root user. Use `--root` only for intentional elevation.
- `outpost:doctor`, `outpost:list`, `outpost:info`, `outpost:process`, and `outpost:verify` expose stable `--json` reports. Instance-specific JSON commands require an explicit name and never prompt. Pre-report failures return a top-level `error` and an unsuccessful exit code. Missing containers and failed provisioning report degraded state.
- Repository-configured `outpost.previews` entries resolve same-origin review paths and optional notes for every instance. Open one with `outpost:open <name> <preview>` or read it from `endpoints.<preview>` in info JSON. Invalid custom entries are omitted from discovery; requesting one explicitly reports its configuration error.
- Outpost serves every instance through its private nginx and PHP-FPM runtime. The **App Processes** column and `outpost:process <name> --json` report only user-configured supervised commands. Add a process name and `--restart` to restart one after long-lived PHP code changes. `outpost:info --json` also exposes the keyed `process_states` map; `unavailable` means the container is stopped or missing, `waiting` means provisioning is incomplete, and `unknown` isolates a failed live-state probe. Table info does not probe Supervisor.
- `outpost:verify --json` is the stable handoff report. It runs host-owned `verify` hooks, checks runtime, container, image, final Git state, a fresh production asset build when `package.json` defines a `build` script, configured project checks, and the application response. API-only apps skip asset building. Dirty worktrees warn without failing; a skipped `Configured checks` row means no project-specific test or lint command ran.
- `outpost:remove` refuses dirty worktrees even with `--force` before running `teardown` hooks. Successful hook file writes do not cause a second refusal. `--discard-changes` explicitly destroys uncommitted work. Teardown runs only for ready, running instances; `--forget` bypasses hook parsing and execution and reports the orphaned container.

### 3. Upgrade and recover

```bash
composer update zacksmash/outpost --with-all-dependencies
php artisan outpost:pull            # refresh the shared base image when needed
php artisan outpost:upgrade --all
php artisan outpost:upgrade <name> --force  # apply config changes to a current image
```

Manifests record the runtime driver, container image reference, digest, and approved Composer path-repository mounts. List and info JSON expose `runtime`, `image`, `image_digest`, `path_repository_mounts`, `configured_image`, `configured_image_digest`, and tri-state `outdated`. The only supported runtime value is `apple-container`.

`outpost:upgrade <name>` and `--all` pull a missing configured image, verify its contract, preflight every selected worktree, and replace outdated or missing containers. They also rebuild a current legacy instance when its sole managed database is not yet the application connection. Add `--force` after any other HTTPS, PHP, resources, services, service exposure, processes, or frontend configuration change; it rebuilds current-image containers and records the current settings. Dirty worktrees are still refused because recovery writes `.env` and runs dependency scripts, builds, and hooks. Source and branches survive; container-local databases and service data reset; Outpost-managed environment values are reconciled; Composer, front-end builds, migrations, and `setup` hooks run again. Previously approved path-repository mounts are reused automatically. Only newly discovered external repositories prompt; use `--mount-path-repos` to approve those without interaction.

Maintenance commands automatically remove the one root-level `dump.rdb` that legacy Outpost Redis configuration may have leaked, but only when Git reports it as untracked and its binary header proves it is a Redis snapshot. Tracked or ambiguous files remain protected by the dirty-worktree guard; never bypass that guard merely because a file has the same name.

Published package config is a copied file, so its official image tag does not move during `composer update`. Run `outpost:doctor` after an update: a Base image warning naming two official tags means `outpost.image` or `OUTPOST_IMAGE` is pinning another release. Update it to the package-shipped image, run `outpost:pull`, then run `outpost:upgrade --all`.

When runtime state is `missing` but the manifest and worktree survive, `outpost:start <name>` recreates the container automatically through the same path, refusing dirty worktrees exactly like `outpost:upgrade`. It preserves `.env` and `APP_KEY`. Lost container-local MySQL, PostgreSQL, Redis, and Mailpit data cannot be recovered; SQLite inside the worktree survives.

### 4. Work safely as an agent

```bash
php artisan outpost:doctor
php artisan outpost agent/task-482 --name=agent-task-482 --no-interaction
php artisan outpost:info agent-task-482 --json
php artisan outpost:exec agent-task-482 -- php artisan test
php artisan outpost:verify agent-task-482 --json
php artisan outpost:open agent-task-482
git -C .outpost/agent-task-482/app status --short
```

- edit only `.outpost/<name>/app` when the task assigns an Outpost worktree
- prefer JSON discovery and `outpost:exec` over parsing tables, guessing URLs, or opening a shell
- before handoff, run `outpost:verify`; it rebuilds production assets when `frontend` is `build` and `package.json` defines a `build` script, runs configured checks, and probes the served application
- use `npm run build -- --watch` only for iterative rebuilding; refresh the browser manually and finish with a successful production build
- inspect Git status before cleanup; commit authorized work from the host with `git -C .outpost/<name>/app ...`
- prefer `outpost:stop` when cleanup safety is uncertain
- never use `--discard-changes` merely to bypass a refusal
- do not mount Composer path repositories unless the user authorized the listed source paths; read-only prevents writes, not disclosure
- treat `.outpost/.cache` as disposable Outpost-managed state, not work product to edit, preserve, or commit
- recommend `expose_services => false` for untrusted review work unless host service access is required

The Git common directory is mounted read-only at its host path, so Git-aware reads and `pint --dirty` work inside the instance. Commits must happen on the host. The instance is a development sandbox, not an adversarial-code sandbox: the assigned worktree is writable, dependency scripts run, and outbound networking is available.

### 5. Configure only when needed

Every instance uses PHP-FPM. Outpost selects a compatible configured PHP version and detects MySQL or PostgreSQL, Redis, and Mailpit from Laravel configuration. Set `services` explicitly when detection is wrong. If the list contains exactly one database service, Outpost makes it the application's sandbox connection and reconciles its `DB_*` values. Listing both databases keeps the application's configured default.

`frontend` defaults to `build`: Outpost uses `npm ci` with a lock file, otherwise `npm install`, then runs the production build. Laravel serves the compiled `public/build` output. Outpost deliberately does not manage Vite's development server, HMR, or `npm run preview`; it is a parallel execution and review sandbox, not a replacement for the primary development environment. Set `frontend` to `none` to skip Node.

Long-running Laravel processes are explicit shell-free argument lists. `@php` resolves to the selected PHP version:

```php
'processes' => [
    'queue' => ['@php', 'artisan', 'queue:work', '--sleep=1'],
    'scheduler' => ['@php', 'artisan', 'schedule:work'],
],
```

Project verification commands use the same shell-free argument shape and run only when `outpost:verify` is invoked:

```php
'checks' => [
    'tests' => ['@php', 'artisan', 'test'],
    'lint' => ['composer', 'lint'],
],
```

Repository lifecycle hooks supplement built-in provisioning. They are read from the host checkout, not the instance branch, so untrusted branch changes cannot inject commands:

```php
'hooks' => [
    'setup' => [
        'search' => ['@php', 'artisan', 'scout:sync-index-settings'],
        'playwright' => ['npx', 'playwright', 'install', 'chromium'],
    ],
    'verify' => ['generate' => ['npm', 'run', 'generate']],
    'teardown' => ['cleanup' => ['@php', 'artisan', 'app:cleanup']],
],
```

Hook failure preserves the instance and fails the lifecycle operation. Hooks always run as the non-root application user; there is no privileged hook mode. The base image supplies Playwright's Ubuntu Chromium dependencies but not a browser binary, so a setup hook may safely download the project's matching browser with `npx playwright install chromium`. Teardown hooks run only when the manifest is ready and the container is running; stopped, missing, and incomplete instances skip them. `outpost:remove --forget` bypasses even malformed hook configuration when runtime recovery is impossible.

Important config values are `domain`, `image`, `dns`, `path`, `resources`, `php`, `frontend`, `https`, `tls.path`, `services`, `expose_services`, `previews`, `processes`, `checks`, `hooks`, `database`, `lifecycle_timeout`, and `timeout`. Image-level changes require `outpost:build --force` and instance upgrades.

Composer path repositories outside the worktree are offered as default-no read-only mounts. Approved mounts are recorded per instance and reused during upgrades and recovery; newly discovered repositories still require explicit approval. Approved relative repositories receive an ignored host bridge so Composer vendor links work for host tools. Do not edit through that bridge unless the external repository is explicitly in scope.

## References

- `README.md` — public lifecycle, safety model, and commands
- `config/outpost.php` — supported configuration

## Examples

- Review a pull request: `php artisan outpost --pr=482 --name=pr-482 --open`.
- Discover service coordinates: read `endpoints.mysql.url`, `endpoints.pgsql.url`, `endpoints.redis.url`, or `endpoints.mailpit.url` from `outpost:info <name> --json`. Mailpit uses the application's HTTP or HTTPS scheme. Use the exact reported URL from the host; inside the instance, use that scheme with `localhost:8025` and curl `--insecure` for HTTPS.
- Open the repository's named review screen: `php artisan outpost:open <name> posts`.
- Restart a stale queue worker: `php artisan outpost:process <name> queue --restart`, then confirm its reported state is `running`.
- Recover a missing container: inspect worktree status, then run `php artisan outpost:start <name>`.
- Upgrade an outdated instance: inspect worktree status, then run `php artisan outpost:upgrade <name>`.
- Apply changed Outpost configuration: inspect worktree status, then run `php artisan outpost:upgrade <name> --force`.
- Verify an agent handoff: run `php artisan outpost:verify <name> --json`, inspect every `FAIL`, and do not claim project tests ran when `Configured checks` is `SKIP`.
- Recreate from another base: remove the instance, then explicitly delete or rename the retained branch before creating it from the new reference.

## Anti-patterns

- do not treat Outpost as a replacement for the primary development environment or as production parity; it intentionally standardizes on PHP-FPM and compiled assets
- do not hand off front-end changes without a successful production build and browser preview
- do not treat `verified: true` as proof of a project test suite when the `Configured checks` row is `SKIP`
- do not configure or claim Docker, Podman, or another runtime; `apple-container` is the only supported driver
- do not edit the primary checkout when assigned an Outpost worktree
- do not use `outpost:shell` when `outpost:exec` expresses the command
- do not put shell command strings or operators in `processes`; provide one argument per array item
- do not configure absolute or network URLs in `previews`; use a same-origin path beginning with one slash and do not reuse built-in endpoint names
- do not use `outpost:process` to control nginx, PHP-FPM, databases, or ad hoc commands; it is limited to application processes recorded in the instance manifest
- do not treat lifecycle hooks from an instance branch as trusted configuration; Outpost intentionally reads them from the host checkout
- do not provide production secrets or sensitive mounts to untrusted code
- do not edit `.outpost/<name>/runtime`; Outpost owns and regenerates runtime configuration
- do not copy or share `vendor` or `node_modules` between instances; Outpost shares only package-manager downloads
- do not document package internals as public API
