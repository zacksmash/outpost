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

## Introduction

Outpost turns any branch of your Laravel application into an isolated, reviewable sandbox powered by Apple's [`container`](https://github.com/apple/container) runtime:

```shell
php artisan outpost feature/billing

# Created [feature-billing]: https://feature-billing-app.outpost
```

Each instance receives its own editable Git worktree, VM, URL, database, and detected services. Outpost doesn't replace your primary development environment — it gives your branches and agents somewhere to build in parallel, so you can preview their actual, compiled output before it ships.

Of course, if you only need to glance at a trusted branch, a Git worktree linked to [Laravel Herd](https://herd.laravel.com) may be all you need. Outpost is for when isolation is the point: every branch — including the ones your agents write — builds and runs inside its own VM instead of natively on your Mac, with a private database and queue workers no other branch can touch, provisioned from a pinned image so a passing `outpost:verify` report means the work is truly ready.

## Requirements

- macOS 26 or newer on Apple silicon
- Apple's `container` CLI 1.2.x, installed from its [signed releases](https://github.com/apple/container/releases)
- A Laravel application in a Git repository with at least one commit
- [`mkcert`](https://github.com/FiloSottile/mkcert), if you would like trusted HTTPS

Apple container is currently the only supported runtime. Outpost's lifecycle is built on a [`RuntimeDriver`](src/Contracts/RuntimeDriver.php) contract, and every instance manifest records the `apple-container` runtime that owns it, so additional drivers may be introduced later without changing the command surface or guessing which engine owns an instance. This extension seam does not imply Docker or Podman support yet.

## Installation

You may install Outpost into your project using Composer. Then, run the `outpost` Artisan command:

```shell
composer require zacksmash/outpost --dev

php artisan outpost
```

The first time you run this command, Outpost will present a single setup plan and, with your approval, start Apple container, configure the `.outpost` publication domain and its DNS resolver, and pull the exact versioned image. macOS may prompt for an administrator password while registering the DNS resolver.

Of course, you may also prepare or inspect your machine explicitly:

```shell
php artisan outpost:install

php artisan outpost:doctor    # Read-only diagnostics; --json available
```

### HTTPS

By default, instances are served over HTTP. To enable trusted HTTPS, install `mkcert` and let the installer persist `OUTPOST_HTTPS=true` in your application's `.env` while preparing the local certificate authority:

```shell
brew install mkcert

php artisan outpost:install --https
```

The `--https` option enables HTTPS for future Artisan processes and prepares exact-host certificates for new instances. Run `outpost:certify` directly only when you need to recreate the trusted certificate setup without changing the preference.

### Custom Images

Outpost's default configuration works without customization. However, if you need to change the installed PHP versions, sandbox credentials, or other image settings, you may publish the configuration file and rebuild the image:

```shell
php artisan vendor:publish --tag="outpost-config"

php artisan outpost:build --force
```

The `outpost:install --local` command builds the image instead of pulling it when the configured image is missing or incompatible. It will never replace an existing compatible image, so reach for `outpost:build --force` when a rebuild is intentional.

### Upgrading Outpost

To upgrade Outpost, update the package via Composer and then upgrade your instances:

```shell
composer update zacksmash/outpost --with-all-dependencies

php artisan outpost:upgrade --all
```

Upgrading pulls and validates the configured image if it is missing, then replaces outdated or missing containers. If you have published `config/outpost.php`, its copied `image` value does not change with Composer updates: update that pin or `OUTPOST_IMAGE` to the release shown in the package's config first. `outpost:doctor` warns when an official configured tag differs from the image shipped by the installed package. Every manifest records the image reference, digest, and approved Composer path-repository mounts used to create its container.

When only Outpost configuration changed, force a rebuild without replacing the worktree:

```shell
php artisan outpost:upgrade billing --force
```

## Creating Instances

To create an instance, run the `outpost` command. Outpost will prompt you for a branch and an instance name — or you may provide everything up front:

```shell
php artisan outpost feature/billing --name=billing --seed
php artisan outpost origin/review/invoices --name=invoices --open
php artisan outpost --pr=482 --name=pr-482 --open
```

Outpost creates a Git worktree beneath `.outpost/<name>/app`, detects your application's runtime and services, boots the VM, prepares `.env` from `.env.example`, installs your dependencies, builds the front end, migrates the database, and waits for a real application response.

Composer and npm downloads are cached once per repository beneath `.outpost/.cache` and reused by new or rebuilt instances. Installed `vendor` and `node_modules` directories remain private to each worktree, so branches never share executable dependencies. The cache survives instance removal and may be deleted at any time.

| Option | Description |
| --- | --- |
| `branch` | Existing local or remote branch, or a new local branch. |
| `--name` | Instance name; defaults to the slugged branch name. |
| `--pr` | GitHub pull request number to fetch into an editable branch. |
| `--remote` | Git remote used with `--pr`; defaults to `origin`. |
| `--open` | Open the application after creation. |
| `--seed` | Seed after migrating. |
| `--mount-path-repos` | Mount Composer path repositories without prompting. |

Remote and pull request refs are checked out as local, editable branches, while existing local branches are always preserved and never reset. Non-interactive creation will not perform privileged first-time setup; run `outpost:install --force` first, adding `--https` when certificate trust changes are allowed.

## Services

Every instance runs PHP-FPM. Outpost inspects your application's configuration to select its PHP version, MySQL or PostgreSQL, Redis, Mailpit, and front-end workflow. You may override service detection using the `services` configuration option. When that list contains exactly one database service, Outpost also makes it the application's sandbox connection and writes its `DB_*` values; listing both keeps the application's configured default.

Detected services listen on their standard ports on the instance's private IP address, so instances never compete for host ports:

| Service | Port |
| --- | ---: |
| MySQL | `3306` |
| PostgreSQL | `5432` |
| Redis | `6379` |
| Mailpit SMTP / UI | `1025` / `8025` |

You may retrieve an instance's URLs and credentials at any time using the `outpost:info` command. Mailpit's web endpoint uses the same HTTP or HTTPS scheme as the application, so copy the reported host URL rather than assuming HTTP on port `8025`. From inside the instance, use that scheme with `localhost:8025` and add curl's `--insecure` option for HTTPS. To keep backing services on the container's loopback interface, set the `expose_services` configuration option to `false`.

### Frontend Assets

By default, Outpost installs your npm dependencies and runs your application's production build once — using `npm ci` when a lock file is present and `npm install` otherwise. Laravel serves the compiled assets from `public/build`; Outpost does not run Vite's development server or `npm run preview`.

PHP and Blade changes are visible on the next request. After changing JavaScript or CSS, rebuild the assets before reviewing or handing off the work:

```shell
php artisan outpost:exec billing -- npm run build

php artisan outpost:open billing
```

For an iterative front-end session, `npm run build -- --watch` will rebuild in place — just refresh your browser to see each build. Always complete one successful production build before declaring agent work ready. If your application has no front end, set the `frontend` configuration option to `none` to skip Node entirely.

### Review Links

Review links give your reviewers named URLs to the exact screens that matter:

```php
'previews' => [
    'posts' => ['path' => '/acme/posts', 'note' => 'Review CRUD behavior'],
    'telescope' => ['path' => '/telescope'],
],
```

Once configured, you may open a review link by name, or discover every resolved URL and note via `outpost:info`:

```shell
php artisan outpost:open billing posts

php artisan outpost:info billing --json
```

Preview paths always stay on the instance's origin and remain available when direct backing-service access is disabled. Invalid preview entries are omitted from discovery, while opening one explicitly reports the configuration error without hiding the application endpoint or other valid previews.

### Application Processes

You may run queue workers, the scheduler, Horizon, or any other long-lived command under Supervisor by defining named, shell-free argument lists:

```php
'processes' => [
    'queue' => ['@php', 'artisan', 'queue:work', '--sleep=1'],
    'scheduler' => ['@php', 'artisan', 'schedule:work'],
],
```

Within a process definition, `@php` resolves to the instance's PHP version. Processes start after provisioning completes, restart with the instance, and write to `outpost:logs`.

You may inspect your configured processes at any time, or restart one after changing long-lived PHP code:

```shell
php artisan outpost:process billing                    # --json available

php artisan outpost:process billing queue --restart
```

Every instance is served by Outpost-managed nginx and PHP-FPM. The **App Processes** column reports only the optional commands you configured above; Outpost keeps its web runtime and backing services private. The `outpost:info --json` report includes a keyed `process_states` map alongside its `processes` name list: stopped containers report `unavailable`, incomplete provisioning reports `waiting`, and a failed live-state probe reports `unknown` for only the affected process. Table output never probes Supervisor.

### Lifecycle Hooks

Lifecycle hooks let you layer repository-specific setup on top of Outpost's built-in provisioning:

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

Hooks are named, shell-free commands read from the host checkout's `config/outpost.php`, so an instance branch can never inject them. `setup` hooks run after new and rebuilt containers are provisioned, `verify` hooks run before handoff checks, and `teardown` hooks run before removal. Within a hook, `@php` resolves to the instance's PHP version.

A failing hook stops the lifecycle operation and preserves the instance for diagnosis. Hooks always run as the non-root application user; Outpost does not expose privileged hooks. The base image includes Playwright's Ubuntu Chromium dependencies, so projects may download their matching browser with `npx playwright install chromium` without requesting system privileges. Teardown hooks only run for a fully provisioned instance whose container is running — stopped, missing, and incomplete instances skip them with a warning — and emergency `--forget` removal bypasses hook parsing and execution entirely.

## Managing Instances

```shell
php artisan outpost:list                          # Inventory; --json available
php artisan outpost:info billing                  # URLs and credentials; --json available
php artisan outpost:open billing                  # Start if needed, then open
php artisan outpost:open billing posts            # App, Mailpit, or a configured review link
php artisan outpost:start billing
php artisan outpost:upgrade billing               # Replace only the container; preserves a clean worktree
php artisan outpost:upgrade --all                 # Upgrade every outdated or missing instance
php artisan outpost:upgrade billing --force       # Apply current config even when the image is current
php artisan outpost:pull                          # Pull or refresh the shared base image
php artisan outpost:stop billing                  # Preserves worktree and data
php artisan outpost:exec billing -- php artisan test
php artisan outpost:process billing               # Inspect managed processes; --json available
php artisan outpost:process billing queue --restart
php artisan outpost:verify billing                # Handoff report; --json available
php artisan outpost:shell billing
php artisan outpost:logs billing --follow
php artisan outpost:remove billing                # Destroys the VM and its data
```

The `outpost:exec` command passes every token after `--` directly to the command — no shell involved — while streaming output and preserving the command's exit code. Both `outpost:exec` and `outpost:shell` run as a host-ID-mapped, non-root user; reach for `--root` only when elevation is truly required.

The `doctor`, `list`, `info`, `process`, and `verify` commands support stable `--json` output. Instance-specific JSON commands require an explicit name, never prompt, and return a top-level `error` with an unsuccessful exit code when a report cannot be produced.

### Verifying Instances

The `outpost:verify` command produces a truthful handoff report. It runs your configured `verify` hooks, checks the runtime, container, exact image, final Git state, and application response, and creates a fresh production build when front-end management is enabled and `package.json` defines a `build` script. API-only applications skip that check, just as provisioning skips front-end work.

You may also define project-specific checks, which run without a shell:

```php
'checks' => [
    'tests' => ['@php', 'artisan', 'test'],
    'lint' => ['composer', 'lint'],
],
```

A dirty worktree is a non-blocking warning, while a skipped "Configured checks" row means no project-specific test or lint command ran. For a stable, agent-readable report, use `outpost:verify <name> --json`; the command exits unsuccessfully when any required check fails and includes bounded command output for diagnosis.

### Upgrading Instances

The `outpost:upgrade` command pulls a missing configured image, verifies its runtime contract, and preflights every selected worktree before deleting any container. By default it replaces outdated or missing containers and repairs legacy manifests whose sole managed database is not yet the application's sandbox connection. Add `--force` to rebuild any other current container and re-read the current HTTPS, PHP, resources, services, service exposure, processes, and frontend settings. This is the maintenance path after changing `config/outpost.php` or its environment values.

Dirty worktrees are always refused, including with `--force`; the flag forces a rebuild, not the destruction or mutation of uncommitted work. A rebuild keeps your source and branch, resets container-local databases and services, reconciles Outpost-managed `.env` values, then refreshes Composer dependencies, front-end builds, migrations, and `setup` hooks. Rebuilt containers also pick up the repository's shared download caches and automatically reuse path-repository mounts previously approved for that instance. Only newly discovered external repositories prompt for approval; add `--mount-path-repos` to approve those without prompting.

Redis instances created before v0.5.3 may leave one root-level `dump.rdb` in the worktree. During upgrade, missing-container recovery, or removal, Outpost deletes it automatically only when the instance uses legacy Redis configuration, Git reports the file as untracked, and its binary header identifies a Redis snapshot. Tracked or ambiguous files remain protected by the normal dirty-worktree guard.

### Local Network Permission

If macOS blocks direct browser or CLI access, allow the calling application under **System Settings → Privacy & Security → Local Network**, then restart it. In the meantime, an agent may probe from inside the instance:

```shell
# HTTP
php artisan outpost:exec billing -- curl --fail --silent --show-error http://localhost

# HTTPS
php artisan outpost:exec billing -- curl --fail --silent --show-error --insecure https://localhost
```

## Worktrees & Safety

The worktree is mounted read-write at `/app`, so you may edit it with your normal host tools. Git metadata is mounted read-only — `git diff` and tools such as `pint --dirty` work inside the instance, but commits must be made from the host:

```shell
git -C .outpost/billing/app status --short

git -C .outpost/billing/app commit -am "Finish billing"
```

Removal refuses a dirty worktree — even with `--force` — before running any `teardown` hook. Preserve the work, or explicitly destroy it with `--discard-changes`. Files intentionally written by a successful teardown will not trigger a second refusal or cause the hooks to run again on retry. `--force` skips prompts and retains the branch; to recreate an instance from another base, remove it and then delete or rename the retained branch yourself.

If the manifest and worktree survive but Apple container no longer has the container, run `outpost:start` or `outpost:upgrade`. Both recreate the container automatically, refuse dirty worktrees, preserve the worktree and application key, remount approved path repositories, and refresh Composer dependencies, front-end builds, and migrations. The missing container's writable service data is already gone and cannot be recovered; SQLite data inside the worktree survives.

If an Apple container VM is stuck, `outpost:remove <name> --forget` removes only Outpost's local worktree and manifest, reports the orphaned container, and prints the command to clean it up later.

Composer path repositories that live outside the worktree are offered as read-only mounts, defaulting to no. Outpost records approved mounts in the instance manifest and reuses them for upgrades and missing-container recovery; a newly discovered repository still requires confirmation or `--mount-path-repos`. Approved relative repositories also receive an ignored, host-side bridge so their Composer symlinks resolve in both the container and your host tools. The bridge itself is a host symlink and cannot enforce read-only access — do not edit the external package through it unless that source was explicitly assigned.

Finally, remember that an instance is a development sandbox, not production parity. Its database credentials are deliberately permissive, and enabled services are reachable from the local container network. Disable `expose_services` when network separation matters more than host database-tool access.

## Configuration

You may publish Outpost's configuration file using the `vendor:publish` Artisan command:

```shell
php artisan vendor:publish --tag="outpost-config"
```

| Key | Default | Description |
| --- | --- | --- |
| `domain` | `outpost` | Local publication domain. |
| `image` | `ghcr.io/zacksmash/outpost:0.5.6` | Exact OCI image used by instances. |
| `dns` | `1.1.1.1` | Nameserver injected into builds and instances. |
| `path` | `.outpost` | Project-relative instance directory. |
| `resources.cpus` | `4` | Virtual CPUs per instance. |
| `resources.memory` | `2G` | Memory per instance. |
| `php` | `['8.4', '8.5']` | PHP versions installed in the image. |
| `frontend` | `build` | Build assets once, or `none` to skip Node. |
| `https` | `false` | Set `true` to require prepared trusted HTTPS. |
| `tls.path` | `.outpost/tls` | Project-relative trusted HTTPS state directory. |
| `services` | `null` | Explicit service list; one listed database becomes the sandbox connection. `null` enables detection. |
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
