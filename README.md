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

Outpost turns any branch of a Laravel application into an isolated development instance powered by Apple's [`container`](https://github.com/apple/container) runtime.

```bash
php artisan outpost feature/billing

# ⚡ The instance is ready: https://feature-billing-app.outpost
```

Each instance has an editable Git worktree, its own VM, URL, database, and detected services. Use it to review a pull request, run another branch beside your current work, or give an agent a disposable sandbox without copying code in or out.

## Requirements

- macOS 26 or newer on Apple silicon
- Apple's `container` CLI 1.2.x from its [signed releases](https://github.com/apple/container/releases)
- A Laravel application in a Git repository with at least one commit
- Optional trusted HTTPS: [`mkcert`](https://github.com/FiloSottile/mkcert)

## Install

```bash
composer require zacksmash/outpost --dev
php artisan outpost
```

The first run presents one setup plan, then can start Apple container, configure the `.outpost` publication domain and DNS resolver, prepare trusted HTTPS, and pull the exact versioned image. macOS may ask for an administrator password when registering DNS or installing certificate trust.

Prepare or inspect the machine explicitly with:

```bash
php artisan outpost:install
php artisan outpost:doctor    # read-only diagnostics
```

For HTTPS, install `mkcert`; Outpost automatically mirrors the primary application's trusted HTTP or HTTPS scheme. Override detection with `OUTPOST_HTTPS=true` or `OUTPOST_HTTPS=false`.

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

## Create an Instance

Run `php artisan outpost` to choose a branch and instance name interactively, or provide everything up front:

```bash
php artisan outpost feature/billing --name=billing --seed
php artisan outpost origin/review/invoices --name=invoices --open
php artisan outpost --pr=482 --name=pr-482 --open
```

Outpost creates a worktree under `.outpost/<name>/app`, detects the application runtime and services, boots the VM, prepares `.env` from `.env.example`, installs dependencies, builds the front end, migrates the database, and waits for a real application response.

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

Outpost reads the application's configuration to select MySQL or PostgreSQL, Redis, Mailpit, PHP-FPM or Octane, and the front-end workflow. Override service detection with the `services` config key.

Detected services use standard ports on the instance's private IP, so instances do not compete for host ports:

| Service | Port |
| --- | ---: |
| MySQL | `3306` |
| PostgreSQL | `5432` |
| Redis | `6379` |
| Mailpit SMTP / UI | `1025` / `8025` |

Run `outpost:info <name>` for URLs and credentials. Set `expose_services` to `false` to keep backing services on container loopback.

### Octane

Octane supports Swoole, RoadRunner, and FrankenPHP behind Outpost's nginx proxy. The default `octane.server` mirrors `OCTANE_SERVER`; use `server => fpm` to force PHP-FPM or configure a runtime explicitly:

```php
'server' => 'octane',
'octane' => ['server' => 'frankenphp'],
```

Outpost polls the application's configured `octane.watch` paths so host edits reload workers across bind mounts. Force a reload with `php artisan outpost:reload <name>`. RoadRunner applications must require `spiral/roadrunner-http`; FrankenPHP requires the application to support its embedded PHP 8.5.

### Front end

The default `frontend => build` installs dependencies and runs the application's build script once. A lock file uses `npm ci`; otherwise Outpost uses `npm install`. Set `frontend` to `vite` for a supervised polling Vite server with HMR, or `none` to skip Node entirely.

### Application processes

Run queue workers, the scheduler, Horizon, or other long-lived commands under Supervisor with shell-free argument lists:

```php
'processes' => [
    'queue' => ['@php', 'artisan', 'queue:work', '--sleep=1'],
    'scheduler' => ['@php', 'artisan', 'schedule:work'],
],
```

`@php` resolves to the selected PHP version. Processes start after provisioning, restart with the instance, and write to `outpost:logs`.

## Manage Instances

```bash
php artisan outpost:list                         # inventory; --json available
php artisan outpost:info billing                 # URLs and credentials; --json available
php artisan outpost:open billing                 # start if needed, then open
php artisan outpost:open billing mailpit         # endpoints: app, mailpit, vite
php artisan outpost:start billing
php artisan outpost:start billing --recreate      # rebuild a missing container around the worktree
php artisan outpost:stop billing                 # preserves worktree and data
php artisan outpost:reload billing               # reload Octane workers
php artisan outpost:exec billing -- php artisan test
php artisan outpost:shell billing
php artisan outpost:logs billing --follow
php artisan outpost:remove billing               # destroys the VM and its data
```

`outpost:exec` passes every token after `--` directly to the command without a shell, streams output, and preserves its exit code. It and `outpost:shell` run as a host-ID-mapped non-root user; use `--root` only when elevation is required.

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

Removal refuses a dirty worktree, even with `--force`. Preserve the work or use `--discard-changes` to explicitly destroy it. `--force` skips prompts and retains the branch. To recreate an instance from another base, remove it and then delete or rename the retained branch yourself.

If the manifest and worktree survive but Apple container no longer has the container, run `outpost:start <name> --recreate`. Outpost pulls the exact image when needed, preserves the worktree and application key, remounts approved path repositories, and reruns Composer plus migrations. The missing container's writable service data is already gone and cannot be recovered; SQLite data inside the worktree survives. Add `--mount-path-repos` in a non-interactive recovery when those repositories are required.

If an Apple container VM is stuck, `outpost:remove <name> --forget` removes only Outpost's local worktree and manifest, reports the orphaned container, and prints its later cleanup command.

Composer path repositories outside the worktree are offered as read-only mounts and default to no. Approved relative repositories also receive an ignored host-side bridge so their Composer symlinks resolve in both the container and host tools. The bridge itself is a host symlink and cannot enforce read-only access, so do not edit the external package through it unless that source was explicitly assigned.

An instance is a development sandbox, not production parity. Its database credentials are deliberately permissive, and enabled services are reachable from the local container network. Disable `expose_services` when network separation matters more than host database-tool access.

## Configuration

Publish configuration with `php artisan vendor:publish --tag="outpost-config"`.

| Key | Default | Description |
| --- | --- | --- |
| `domain` | `outpost` | Local publication domain. |
| `image` | `ghcr.io/zacksmash/outpost:0.1.2` | Exact OCI image used by instances. |
| `dns` | `1.1.1.1` | Nameserver injected into builds and instances. |
| `path` | `.outpost` | Project-relative instance directory. |
| `resources.cpus` | `4` | Virtual CPUs per instance. |
| `resources.memory` | `2G` | Memory per instance. |
| `php` | `['8.4', '8.5']` | PHP versions installed in the image. |
| `server` | `auto` | `auto`, `fpm`, or `octane`. |
| `octane.server` | `auto` | `swoole`, `roadrunner`, or `frankenphp`. |
| `frontend` | `build` | `build`, `vite`, or `none`. |
| `vite.port` | `5173` | Public Vite port on the instance IP. |
| `vite.hot_file` | `public/hot` | Application-relative Laravel hot file. |
| `https` | `auto` | Mirror the primary app; accepts `true` or `false`. |
| `services` | `null` | Explicit service list; `null` enables detection. |
| `expose_services` | `true` | Expose detected services on the instance IP. |
| `processes` | `[]` | Named supervised argument lists. |
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
