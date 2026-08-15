<p align="center">
    <img src="https://raw.githubusercontent.com/zacksmash/outpost/main/art/outpost.png" width="640" alt="Outpost — A Laravel Package">
</p>

<p align="center">
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/v/zacksmash/outpost.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/php-v/zacksmash/outpost.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://badge.laravel.cloud/badge/zacksmash/outpost"><img src="https://badge.laravel.cloud/badge/zacksmash/outpost?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/zacksmash/outpost/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/zacksmash/outpost/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/dt/zacksmash/outpost.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Outpost turns any branch of a Laravel application into an isolated, reviewable sandbox powered by Apple's [`container`](https://github.com/apple/container) runtime.

```bash
php artisan outpost feature/billing

# ⚡ The instance is ready: https://feature-billing-app.outpost
```

Each instance has an editable Git worktree, its own VM, URL, database, and detected services. Outpost does not replace your primary development environment; it gives branches and agents somewhere to build in parallel, then lets you preview their actual compiled output before it ships.

## Requirements

- macOS 26 or newer on Apple silicon
- Apple's `container` CLI 1.2.x from its [signed releases](https://github.com/apple/container/releases)
- A Laravel application in a Git repository with at least one commit
- Optional trusted HTTPS: [`mkcert`](https://github.com/FiloSottile/mkcert)

Apple container is the only supported runtime driver today. Outpost's lifecycle depends on a [`RuntimeDriver`](src/Contracts/RuntimeDriver.php) contract and records `apple-container` in every instance manifest so another driver can be introduced later without changing the command surface or guessing which engine owns an instance. This extension seam does not imply Docker or Podman support yet.

## Install

```bash
composer require zacksmash/outpost --dev
php artisan outpost
```

The first run presents one setup plan, then can start Apple container, configure the `.outpost` publication domain and DNS resolver, and pull the exact versioned image. macOS may ask for an administrator password when registering DNS.

Prepare or inspect the machine explicitly with:

```bash
php artisan outpost:install
php artisan outpost:doctor    # read-only diagnostics
```

HTTP is the default. To require trusted HTTPS, set `OUTPOST_HTTPS=true`, install `mkcert`, and prepare the local authority:

```bash
brew install mkcert
php artisan outpost:certify
```

### Custom images

The published configuration works without customization. To change installed PHP versions, sandbox credentials, or other image settings:

```bash
php artisan vendor:publish --tag="outpost-config"
php artisan outpost:build --force
```

`outpost:install --local` builds instead of pulling when the configured image is missing or incompatible. It does not replace an existing compatible image; use `outpost:build --force` when a rebuild is intentional.

### Upgrade

```bash
composer update zacksmash/outpost --with-all-dependencies
php artisan outpost:upgrade --all
```

Upgrade pulls and validates a missing configured image, then replaces only outdated or missing containers. If `config/outpost.php` was published, update its pinned `image` value or `OUTPOST_IMAGE` first. Every manifest records the image reference and digest used to create its container.

## Create an Instance

Run `php artisan outpost` to choose a branch and instance name interactively, or provide everything up front:

```bash
php artisan outpost feature/billing --name=billing --seed
php artisan outpost origin/review/invoices --name=invoices --open
php artisan outpost --pr=482 --name=pr-482 --open
```

Outpost creates a worktree under `.outpost/<name>/app`, detects the application runtime and services, boots the VM, prepares `.env` from `.env.example`, installs dependencies, builds the front end, migrates the database, and waits for a real application response.

Composer and npm downloads are cached once per repository under `.outpost/.cache` and reused by new or rebuilt instances. Installed `vendor` and `node_modules` directories remain private to each worktree, so branches never share executable dependencies. The cache survives instance removal and is disposable.

| Option | Description |
| --- | --- |
| `branch` | Existing local or remote branch, or a new local branch. |
| `--name` | Instance name; defaults to the slugged branch name. |
| `--pr` | GitHub pull request number to fetch into an editable branch. |
| `--remote` | Git remote used with `--pr`; defaults to `origin`. |
| `--open` | Open the application after creation. |
| `--seed` | Seed after migrating. |
| `--mount-path-repos` | Mount Composer path repositories without prompting. |

Remote and pull-request refs become local editable branches. Existing local branches are preserved and never reset. Non-interactive creation does not perform privileged first-time setup; run `outpost:install --force` first, adding `--https` when certificate trust changes are allowed.

## What Runs

Every instance uses PHP-FPM. Outpost reads the application's configuration to select its PHP version, MySQL or PostgreSQL, Redis, Mailpit, and front-end workflow. Override service detection with the `services` config key.

Detected services use standard ports on the instance's private IP, so instances do not compete for host ports:

| Service | Port |
| --- | ---: |
| MySQL | `3306` |
| PostgreSQL | `5432` |
| Redis | `6379` |
| Mailpit SMTP / UI | `1025` / `8025` |

Run `outpost:info <name>` for URLs and credentials. Set `expose_services` to `false` to keep backing services on container loopback.

### Front end

The default `frontend => build` installs dependencies and runs the application's production build once. A lock file uses `npm ci`; otherwise Outpost uses `npm install`. Laravel serves the resulting `public/build` assets—Outpost does not run Vite's development server or `npm run preview`.

PHP and Blade changes are visible on the next request. After changing JavaScript or CSS, rebuild before reviewing or handing off the work:

```bash
php artisan outpost:exec billing -- npm run build
php artisan outpost:open billing
```

For an iterative front-end session, `npm run build -- --watch` can rebuild in place; refresh the browser to see each build. Always complete one successful production build before declaring agent work ready. Set `frontend` to `none` to skip Node entirely.

### Review links

Give reviewers named links to the exact screens that matter:

```php
'previews' => [
    'posts' => ['path' => '/acme/posts', 'note' => 'Review CRUD behavior'],
    'telescope' => ['path' => '/telescope'],
],
```

Run `php artisan outpost:open billing posts`, or discover every resolved URL and note through `outpost:info billing --json`. Paths stay on the instance origin and remain available when direct backing-service access is disabled. Invalid custom preview entries are omitted from discovery; opening one explicitly reports the configuration error without hiding the application endpoint or other valid previews.

### Application processes

Run queue workers, the scheduler, Horizon, or other long-lived commands under Supervisor with shell-free argument lists:

```php
'processes' => [
    'queue' => ['@php', 'artisan', 'queue:work', '--sleep=1'],
    'scheduler' => ['@php', 'artisan', 'schedule:work'],
],
```

`@php` resolves to the selected PHP version. Processes start after provisioning, restart with the instance, and write to `outpost:logs`.

Inspect every configured process or restart one after changing long-lived PHP code:

```bash
php artisan outpost:process billing                 # --json available
php artisan outpost:process billing queue --restart
```

Only configured application processes are controllable; Outpost keeps nginx, PHP-FPM, and backing services private. `outpost:info --json` preserves its `processes` name list and adds a keyed `process_states` map. Stopped containers report `unavailable`, incomplete provisioning reports `waiting`, and a failed live-state probe reports `unknown` for only the affected process. Table output does not probe Supervisor.

### Lifecycle hooks

Add repository-specific setup without replacing Outpost's built-in provisioning:

```php
'hooks' => [
    'setup' => ['search' => ['@php', 'artisan', 'scout:sync-index-settings']],
    'verify' => ['generate' => ['npm', 'run', 'generate']],
    'teardown' => ['cleanup' => ['@php', 'artisan', 'app:cleanup']],
],
```

Hooks are named shell-free commands read from the host checkout's `config/outpost.php`, so an instance branch cannot inject them. `setup` runs after new and rebuilt containers are provisioned, `verify` runs before handoff checks, and `teardown` runs before removal. A failure stops the lifecycle operation and preserves the instance for diagnosis. Teardown runs only for a fully provisioned instance whose container is running; stopped, missing, and incomplete instances skip it with a warning. Emergency `--forget` removal bypasses hook parsing and execution entirely. `@php` selects the instance PHP version.

## Manage Instances

```bash
php artisan outpost:list                         # inventory; --json available
php artisan outpost:info billing                 # URLs and credentials; --json available
php artisan outpost:open billing                 # start if needed, then open
php artisan outpost:open billing posts           # app, mailpit, or a configured review link
php artisan outpost:start billing
php artisan outpost:upgrade billing               # replace only the container; preserves a clean worktree
php artisan outpost:upgrade --all                 # upgrade every outdated or missing instance
php artisan outpost:pull                         # pull or refresh the shared base image
php artisan outpost:stop billing                 # preserves worktree and data
php artisan outpost:exec billing -- php artisan test
php artisan outpost:process billing                 # inspect managed processes; --json available
php artisan outpost:process billing queue --restart
php artisan outpost:verify billing               # handoff report; --json available
php artisan outpost:shell billing
php artisan outpost:logs billing --follow
php artisan outpost:remove billing               # destroys the VM and its data
```

`outpost:exec` passes every token after `--` directly to the command without a shell, streams output, and preserves its exit code. It and `outpost:shell` run as a host-ID-mapped non-root user; use `--root` only when elevation is required.

`outpost:verify` runs configured `verify` hooks, checks the runtime, container, exact image, final Git state, and application response, and creates a fresh production build when front-end management is enabled and `package.json` defines a `build` script. API-only applications skip that row just as provisioning skips front-end work. Every named `checks` command then runs without a shell. A dirty worktree is a non-blocking warning; a skipped `Configured checks` row means no project-specific test or lint command ran:

```php
'checks' => [
    'tests' => ['@php', 'artisan', 'test'],
    'lint' => ['composer', 'lint'],
],
```

Use `outpost:verify <name> --json` for a stable agent-readable report. The command exits unsuccessfully when any required check fails and includes bounded command output for diagnosis.

`outpost:upgrade` pulls a missing configured image, verifies its runtime contract, and preflights every selected worktree before deleting any container. Dirty worktrees are always refused. The command keeps source and branches, resets container-local databases and services, then refreshes Composer, front-end builds, migrations, and `setup` hooks. Rebuilt containers also pick up the repository's shared download caches. Add `--mount-path-repos` for non-interactive external repository mounts.

If macOS blocks direct browser or CLI access, allow the calling application under **System Settings → Privacy & Security → Local Network**, then restart it. An agent can probe from inside the instance:

```bash
# HTTP
php artisan outpost:exec billing -- curl --fail --silent --show-error http://localhost

# HTTPS
php artisan outpost:exec billing -- curl --fail --silent --show-error --insecure https://localhost
```

## Worktrees and Safety

The worktree is mounted read-write at `/app`; edit it with normal host tools. Git metadata is mounted read-only so `git diff` and tools such as `pint --dirty` work inside the instance, but commits must be made from the host:

```bash
git -C .outpost/billing/app status --short
git -C .outpost/billing/app commit -am "Finish billing"
```

Removal refuses a dirty worktree, even with `--force`, before running any `teardown` hook. Preserve the work or use `--discard-changes` to explicitly destroy it. Files intentionally written by a successful teardown do not trigger a second refusal or cause the hook to run again on retry. `--force` skips prompts and retains the branch. To recreate an instance from another base, remove it and then delete or rename the retained branch yourself.

If the manifest and worktree survive but Apple container no longer has the container, run `outpost:start <name>` or `outpost:upgrade <name>`. Both recreate the container automatically, refuse dirty worktrees, preserve the worktree and application key, remount approved path repositories, and refresh Composer, front-end builds, and migrations. The missing container's writable service data is already gone and cannot be recovered; SQLite data inside the worktree survives.

If an Apple container VM is stuck, `outpost:remove <name> --forget` removes only Outpost's local worktree and manifest, reports the orphaned container, and prints its later cleanup command.

Composer path repositories outside the worktree are offered as read-only mounts and default to no. Approved relative repositories also receive an ignored host-side bridge so their Composer symlinks resolve in both the container and host tools. The bridge itself is a host symlink and cannot enforce read-only access, so do not edit the external package through it unless that source was explicitly assigned.

An instance is a development sandbox, not production parity. Its database credentials are deliberately permissive, and enabled services are reachable from the local container network. Disable `expose_services` when network separation matters more than host database-tool access.

## Configuration

Publish configuration with `php artisan vendor:publish --tag="outpost-config"`.

| Key | Default | Description |
| --- | --- | --- |
| `domain` | `outpost` | Local publication domain. |
| `image` | `ghcr.io/zacksmash/outpost:0.3.0` | Exact OCI image used by instances. |
| `dns` | `1.1.1.1` | Nameserver injected into builds and instances. |
| `path` | `.outpost` | Project-relative instance directory. |
| `resources.cpus` | `4` | Virtual CPUs per instance. |
| `resources.memory` | `2G` | Memory per instance. |
| `php` | `['8.4', '8.5']` | PHP versions installed in the image. |
| `frontend` | `build` | Build assets once, or `none` to skip Node. |
| `https` | `false` | Set `true` to require prepared trusted HTTPS. |
| `tls.path` | `.outpost/tls` | Project-relative trusted HTTPS state directory. |
| `services` | `null` | Explicit service list; `null` enables detection. |
| `expose_services` | `true` | Expose detected services on the instance IP. |
| `previews` | `[]` | Named same-origin review paths with optional notes. |
| `processes` | `[]` | Named supervised argument lists. |
| `checks` | `[]` | Named shell-free commands run by `outpost:verify`. |
| `hooks` | `setup`, `verify`, and `teardown`: `[]` | Host-owned shell-free lifecycle commands. |
| `database` | `outpost` / `outpost` / `password` | Sandbox credentials. |
| `lifecycle_timeout` | `30` | Timeout for quick VM lifecycle operations. |
| `timeout` | `60` | Timeout for the application readiness check. |

## Changelog

See [CHANGELOG](CHANGELOG.md) for recent changes.

## Contributing

See the [contributing guide](.github/CONTRIBUTING.md).

## Security

Report vulnerabilities through the [security policy](.github/SECURITY.md).

## Credits

- [Zack Warren](https://github.com/zacksmash)
- [All Contributors](../../contributors)

## License

Outpost is open-sourced software licensed under the [MIT license](LICENSE.md).
