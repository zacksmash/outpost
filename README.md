<p align="center">
    <img src="https://raw.githubusercontent.com/zacksmash/outpost/main/art/outpost.png" width="640" alt="Outpost, a Laravel package">
</p>

<p align="center">
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/v/zacksmash/outpost.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/php-v/zacksmash/outpost.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://badge.laravel.cloud/badge/zacksmash/outpost"><img src="https://badge.laravel.cloud/badge/zacksmash/outpost?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/zacksmash/outpost/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/zacksmash/outpost/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/dt/zacksmash/outpost.svg?style=flat-square" alt="Total Downloads"></a>
    <a href="https://github.com/laravel/boost"><img src="https://badge.laravel.cloud/boost-badge.svg?style=flat-square" alt="Total Downloads"></a>
</p>

## Introduction

Outpost turns any branch of your Laravel application into an isolated, reviewable instance powered by Apple's [`container`](https://github.com/apple/container) runtime:

```shell
php artisan outpost feature/billing

# Created [feature-billing]: https://feature-billing-app.outpost
```

Each instance gets its own editable Git worktree, VM, URL, database, and detected services. Outpost does not replace your primary development environment. It runs branches in parallel and gives you a URL for the compiled application before you merge it.

If you only need to inspect a trusted branch, a Git worktree linked to [Laravel Herd](https://herd.laravel.com) may be enough. Use Outpost when the branch needs isolation. Every branch, including agent-written code, builds and runs inside its own VM instead of on your Mac. Its database and queue workers belong to that instance. A pinned image makes `outpost:verify` repeatable across instances.

## Requirements

- macOS 26 or newer on Apple silicon
- Apple's `container` CLI 1.2.x–1.3.x, installed from its [signed releases](https://github.com/apple/container/releases)
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

You may also prepare or inspect your machine directly:

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

### Custom images

Outpost's default configuration works without customization. However, if you need to change the installed PHP versions, instance credentials, or other image settings, you may publish the configuration file and rebuild the image:

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

## Creating instances

Run `outpost` to create an instance. Without arguments, it prompts for a branch and suggests a name. For example, `feature/billing` becomes `feature-billing`. Accept the name, replace it, or pass everything in one command:

```shell
php artisan outpost feature/billing --name=billing --seed
php artisan outpost origin/review/invoices --name=invoices --open
php artisan outpost --pr=482 --name=pr-482 --open
```

A supplied `--name` is normalized to a URL-friendly slug rather than rejected, so `--name=feature/billing` creates `feature-billing`; Outpost reports the substitution whenever normalization changes what you typed. A branch argument that matches an `outpost:*` subcommand — `php artisan outpost list` instead of `php artisan outpost:list` — is refused with the intended command, unless a local branch by that name actually exists. A branch too long to fit the container's DNS label limit is truncated to a whole-word boundary and given a short, deterministic hash suffix, so the same branch always derives the same name. The instance URL is `https://<name>-<project-directory>.<domain>`, so the container is scoped to the project that created it and identical instance names in different projects never collide. The container name and URL are recorded in the manifest at creation time and are never rewritten, even if the project directory is later renamed.

Outpost creates a Git worktree beneath `.outpost/<name>/app`, detects your application's runtime and services, boots the VM, prepares `.env` from `.env.example`, installs your dependencies, builds the front end, migrates the database, and waits for a real application response.

Composer and npm downloads are cached once per repository beneath `.outpost/.cache` and reused by new or rebuilt instances. Installed `vendor` and `node_modules` directories remain private to each worktree, so branches never share executable dependencies. The cache survives instance removal and may be deleted at any time.

| Option | Description |
| --- | --- |
| `branch` | Existing local or remote branch, or a new local branch. |
| `--name` | Instance name; normalized to a slug, defaulting to a name derived from the branch. |
| `--pr` | GitHub pull request number to fetch into an editable branch. |
| `--remote` | Git remote used with `--pr`; defaults to `origin`. |
| `--open` | Open the application after creation. |
| `--seed` | Seed after migrating. |
| `--mount-path-repos` | Mount Composer path repositories without prompting. |

Remote and pull request refs are checked out as local, editable branches, while existing local branches are always preserved and never reset. Non-interactive creation will not perform privileged first-time setup; run `outpost:install --force` first, adding `--https` when certificate trust changes are allowed.

Booting and provisioning briefly holds tens of thousands of host file descriptors per instance, so at most `max_concurrent_provisions` creations, recreations, upgrades, and starts boot at the same time — the default is 3. Later ones print `Waiting for a provisioning slot` and proceed automatically when a slot frees, so launch as many as you like at once; Outpost does the pacing. A killed or crashed provision releases its slot immediately. The limit is per project: provisioning several repositories in parallel multiplies the load, so lower it accordingly. Set `OUTPOST_MAX_CONCURRENT_PROVISIONS=0` to remove the limit.

## Services

Every instance runs PHP-FPM. Outpost inspects your application's configuration to select its PHP version, MySQL or PostgreSQL, Redis, Mailpit, and front-end workflow. You may override service detection using the `services` configuration option. When that list contains exactly one database service, Outpost also makes it the application's instance connection and writes its `DB_*` values; listing both keeps the application's configured default.

Detected services listen on their standard ports on the instance's private IP address, so instances never compete for host ports:

| Service | Port |
| --- | ---: |
| MySQL | `3306` |
| PostgreSQL | `5432` |
| Redis | `6379` |
| Mailpit SMTP / UI | `1025` / `8025` |

You may retrieve an instance's URLs and credentials at any time using the `outpost:info` command. Mailpit's web endpoint uses the same HTTP or HTTPS scheme as the application, so copy the reported host URL rather than assuming HTTP on port `8025`. From inside the instance, use that scheme with `localhost:8025` and add curl's `--insecure` option for HTTPS. To keep backing services on the container's loopback interface, set the `expose_services` configuration option to `false`.

### Frontend assets

By default, Outpost installs npm dependencies and runs the application's production build once. It uses `npm ci` when a lock file exists and `npm install` otherwise. Laravel serves the compiled assets from `public/build`. Outpost does not run Vite's development server or `npm run preview`.

PHP and Blade changes are visible on the next request. After changing JavaScript or CSS, rebuild the assets before reviewing or handing off the work:

```shell
php artisan outpost:exec billing -- npm run build

php artisan outpost:open billing
```

For an iterative front-end session, `npm run build -- --watch` rebuilds in place. Refresh the browser after each build. Complete one production build before handing off the work. If the application has no front end, set `frontend` to `none` to skip Node.

### Review links

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

### Application processes

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

### Lifecycle hooks

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

A failing hook stops the lifecycle operation and preserves the instance for diagnosis. Hooks run as the non-root application user. Outpost has no privileged hook mode. The base image includes Playwright's Ubuntu Chromium dependencies, so projects may download their matching browser with `npx playwright install chromium` without system privileges. Teardown hooks run only when the instance is fully provisioned and its container is running. Stopped, missing, and incomplete instances skip them with a warning. Emergency removal with `--forget` skips hook parsing and execution.

Some project state is per-instance by nature and cannot ship in the image: the Playwright browser binary must match the project's pinned Playwright version, and Passport encryption keys are application credentials generated by `php artisan passport:keys`. When `outpost:doctor` detects Playwright or Passport in the application without a covering `setup` hook, it suggests the exact hook to add, so every fresh instance lands ready to run those tests.

### Host-managed secrets

Outpost keeps real credentials out of the instance. List the environment keys you consider secret in `config/outpost.php`, then store each value once on the host:

```php
'secrets' => [
    'STRIPE_SECRET',
    'OPENAI_API_KEY',
],
```

```shell
php artisan outpost:secret set STRIPE_SECRET      # Prompts for the value; never echoed
php artisan outpost:secret list                   # Shows which declared keys are stored
php artisan outpost:secret forget STRIPE_SECRET
```

Values live in your macOS Keychain under a project-specific account. At boot, Outpost passes them through a host-only file. Values never appear on the command line or in the worktree `.env`. Outpost reads the `secrets` list from the host checkout, as it does for lifecycle hooks, so an instance branch cannot change which keys resolve. Outpost rejects keys that collide with values it manages, including `APP_URL`, `DB_*`, and the package-manager cache directories.

Creating or recreating an instance fails with the exact `outpost:secret set` remedy when a declared key has no stored value, and `outpost:doctor` reports the same gap. `outpost:secret list` and every error message reveal only whether a value is present, never the value itself.

This prevents a real key from landing in the branch or being read from the worktree by an agent. It does not hide values from code running inside the instance because that code receives them as environment variables. If the application runs `config:cache` inside the instance, Laravel writes resolved values into the compiled config cache. Use provider test keys and narrowly scoped tokens for disposable instances.

## Command reference

Arguments in angle brackets are required. Arguments in square brackets are optional. Put `--` before the command passed to `outpost:exec` so Artisan does not parse the inner command's options.

| Command | Arguments and options | Usage |
| --- | --- | --- |
| `outpost [branch]` | `--name=<name>`<br>`--pr=<number>`<br>`--remote=<remote>`<br>`--open`<br>`--seed`<br>`--mount-path-repos` | Create an instance from a local branch, remote branch, new branch, or GitHub pull request.<br>`php artisan outpost feature/billing --name=billing --open` |
| `outpost:build` | `--force` | Build the configured base image on this Mac. Use `--force` to replace an existing image.<br>`php artisan outpost:build --force` |
| `outpost:certify` | `--force` | Prepare trusted HTTPS certificates. Use `--force` to recreate them.<br>`php artisan outpost:certify` |
| `outpost:doctor` | `--json` | Check the host, runtime, image, project, HTTPS, and declared secrets.<br>`php artisan outpost:doctor --json` |
| `outpost:exec [name] -- <command> [arguments...]` | `name` selects the instance.<br>`--root` runs as root.<br>`--timeout` kills the command after that many seconds. | Run a command without a shell and return its exit code. An expired `--timeout` exits with code `124`.<br>`php artisan outpost:exec billing -- php artisan test` |
| `outpost:info [name]` | `--json` | Show an instance's branch, state, image, endpoints, credentials, and configured processes.<br>`php artisan outpost:info billing --json` |
| `outpost:install` | `--force`<br>`--https`<br>`--local` | Configure the runtime, DNS, image, and optional HTTPS for this project. `--local` builds a missing image instead of pulling it.<br>`php artisan outpost:install --https` |
| `outpost:list` | `--json` | List this project's instances and their current state.<br>`php artisan outpost:list` |
| `outpost:logs [name]` | `--follow` | Read nginx, PHP, service, and configured process logs. Use `--follow` to keep streaming.<br>`php artisan outpost:logs billing --follow` |
| `outpost:open [name] [endpoint]` | `endpoint` defaults to `app`. Use `mailpit` or a configured preview name for another endpoint. | Start the instance if needed, then open its endpoint in the default browser.<br>`php artisan outpost:open billing posts` |
| `outpost:process [name] [process]` | `--restart`<br>`--json` | Inspect every configured application process, inspect one process, or restart one process.<br>`php artisan outpost:process billing queue --restart` |
| `outpost:pull` | `--force` | Pull the configured base image. Use `--force` when it already exists locally.<br>`php artisan outpost:pull --force` |
| `outpost:recover [name]` | `--force` | Recover one wedged instance without touching its siblings: kill the host `container exec` clients attached to it, stop its container, and start it again. Non-interactive recovery requires the explicit `--force`.<br>`php artisan outpost:recover billing --force` |
| `outpost:remove [name]` | `--force`<br>`--delete-branch`<br>`--discard-changes`<br>`--forget` | Remove the container, worktree, and local data. Non-interactive removal requires the explicit `--force`. `--delete-branch` also deletes the instance branch. `--discard-changes` permits deletion of a dirty worktree. `--forget` skips runtime access and teardown hooks.<br>`php artisan outpost:remove billing` |
| `outpost:secret <action> [key]` | `action` is `set`, `list`, or `forget`.<br>`key` is required by `set` and `forget`. | Store project-scoped values in the macOS Keychain, check which declared values exist, or remove one.<br>`php artisan outpost:secret set STRIPE_SECRET` |
| `outpost:shell [name]` | `--root` | Open an interactive shell as the application user or root.<br>`php artisan outpost:shell billing` |
| `outpost:start [name]` | `--mount-path-repos` | Start a stopped instance. If its container is missing, rebuild it from the existing clean worktree.<br>`php artisan outpost:start billing` |
| `outpost:stop [name]` | None. | Stop the container without removing its worktree or container-local data.<br>`php artisan outpost:stop billing` |
| `outpost:upgrade [name]` | `--all`<br>`--force`<br>`--mount-path-repos` | Rebuild outdated or missing containers. `--all` selects every eligible instance. `--force` also rebuilds current containers from today's config.<br>`php artisan outpost:upgrade --all` |
| `outpost:verify [name]` | `--json` | Run the handoff checks, configured verification hooks, and project checks.<br>`php artisan outpost:verify billing --json` |

The `outpost:exec` command passes every token after `--` directly to the command. It does not invoke a shell. It streams output and returns the command's exit code. Both `outpost:exec` and `outpost:shell` run as a host-ID-mapped, non-root user. Use `--root` only when the command needs it.

There is no timeout by default — a cold `composer install` legitimately outlasts any cap Outpost could pick. Scripts and agents that must fail fast can pass `--timeout=<seconds>`: the deadline is enforced inside the instance, killing the real command (TERM, then KILL) and exiting with code `124`, distinct from the inner command's own failure codes. A host-side backstop caps the exec client slightly later; if that backstop is what fires, the exec session itself is wedged and the failure message points to `outpost:recover`.

The `doctor`, `list`, `info`, `process`, and `verify` commands support stable `--json` output. Instance-specific JSON commands require an explicit name, never prompt, and return a top-level `error` with an unsuccessful exit code when a report cannot be produced.

### Verifying instances

The `outpost:verify` command produces a truthful handoff report. It runs your configured `verify` hooks, checks the runtime, container, exact image, final Git state, and application response, and creates a fresh production build when front-end management is enabled and `package.json` defines a `build` script. API-only applications skip that check, just as provisioning skips front-end work.

You may also define project-specific checks, which run without a shell:

```php
'checks' => [
    'tests' => ['@php', 'artisan', 'test'],
    'lint' => ['composer', 'lint'],
],
```

A dirty worktree is a non-blocking warning, while a skipped "Configured checks" row means no project-specific test or lint command ran. For a stable, agent-readable report, use `outpost:verify <name> --json`; the command exits unsuccessfully when any required check fails and includes bounded command output for diagnosis.

### Upgrading instances

The `outpost:upgrade` command pulls a missing configured image, verifies its runtime contract, and preflights every selected worktree before deleting any container. By default it replaces outdated or missing containers and repairs legacy manifests whose sole managed database is not yet the application's instance connection. Add `--force` to rebuild any other current container and re-read the current HTTPS, PHP, resources, services, service exposure, processes, and frontend settings. This is the maintenance path after changing `config/outpost.php` or its environment values.

Dirty worktrees are always refused, including with `--force`; the flag forces a rebuild, not the destruction or mutation of uncommitted work. A rebuild keeps your source and branch, resets container-local databases and services, reconciles Outpost-managed `.env` values, then refreshes Composer dependencies, front-end builds, migrations, and `setup` hooks. Rebuilt containers also pick up the repository's shared download caches and automatically reuse path-repository mounts previously approved for that instance. Only newly discovered external repositories prompt for approval; add `--mount-path-repos` to approve those without prompting.

Redis instances created before v0.5.3 may leave one root-level `dump.rdb` in the worktree. During upgrade, missing-container recovery, or removal, Outpost deletes it automatically only when the instance uses legacy Redis configuration, Git reports the file as untracked, and its binary header identifies a Redis snapshot. Tracked or ambiguous files remain protected by the normal dirty-worktree guard.

### Local network permission

If macOS blocks direct browser or CLI access, allow the calling application under **System Settings → Privacy & Security → Local Network**, then restart it. In the meantime, an agent may probe from inside the instance:

```shell
# HTTP
php artisan outpost:exec billing -- curl --fail --silent --show-error http://localhost

# HTTPS
php artisan outpost:exec billing -- curl --fail --silent --show-error --insecure https://localhost
```

## Worktrees and safety

The worktree is mounted read-write at `/app`, so you may edit it with normal host tools. Git metadata is mounted read-only. `git diff` and tools such as `pint --dirty` work inside the instance, but you must commit from the host:

```shell
git -C .outpost/billing/app status --short

git -C .outpost/billing/app commit -am "Finish billing"
```

Removal refuses a dirty worktree, even with `--force`, before running a `teardown` hook. Preserve the work or destroy it with `--discard-changes`. Files written by a successful teardown do not trigger another refusal or rerun the hooks on retry. `--force` skips prompts and retains the branch; add `--delete-branch` to delete the branch too. Without a terminal — including `--no-interaction` — removal refuses without an explicit `--force` and exits non-zero instead of silently declining, so scripts and agents never mistake a declined prompt for success. To recreate an instance from another base, remove it with `--delete-branch`, or delete or rename the retained branch yourself.

If the manifest and worktree survive but Apple container no longer has the container, run `outpost:start` or `outpost:upgrade`. Both recreate the container automatically, refuse dirty worktrees, preserve the worktree and application key, remount approved path repositories, and refresh Composer dependencies, front-end builds, and migrations. The missing container's writable service data is already gone and cannot be recovered; SQLite data inside the worktree survives.

If one instance wedges — typically a stale exec session that blocks `outpost:stop` and `outpost:exec` — run `outpost:recover <name>`. It kills the host `container exec` clients attached to that container, stops it, and starts it again, never touching other instances. Prefer it over `container system stop && container system start`, which restarts the whole runtime and takes down every sibling instance with it.

If an Apple container VM is stuck beyond recovery, `outpost:remove <name> --forget` removes only Outpost's local worktree and manifest, reports the orphaned container, and prints the command to clean it up later.

Outpost offers Composer path repositories outside the worktree as read-only mounts and defaults to no. It records approved mounts in the instance manifest and reuses them for upgrades and missing-container recovery. A new repository still requires confirmation or `--mount-path-repos`. Approved relative repositories also get an ignored host bridge so Composer symlinks resolve in the container and in host tools. The bridge is a host symlink and cannot enforce read-only access. Do not edit the external package through it unless that source was assigned to you.

Finally, remember that an instance is a development environment, not production parity. Its database credentials are deliberately permissive, and enabled services are reachable from the local container network. Disable `expose_services` when network separation matters more than host database-tool access.

## Configuration

You may publish Outpost's configuration file using the `vendor:publish` Artisan command:

```shell
php artisan vendor:publish --tag="outpost-config"
```

| Key | Default | Description |
| --- | --- | --- |
| `domain` | `outpost` | Local publication domain. |
| `image` | `ghcr.io/zacksmash/outpost:0.8.2` | Exact OCI image used by instances. |
| `dns` | `1.1.1.1` | Nameserver injected into builds and instances. |
| `path` | `.outpost` | Project-relative instance directory. |
| `resources.cpus` | `4` | Virtual CPUs per instance. |
| `resources.memory` | `2G` | Memory per instance. |
| `php` | `['8.4', '8.5']` | PHP versions installed in the image. |
| `frontend` | `build` | Build assets once, or `none` to skip Node. |
| `https` | `false` | Set `true` to require prepared trusted HTTPS. |
| `tls.path` | `.outpost/tls` | Project-relative trusted HTTPS state directory. |
| `services` | `null` | Explicit service list; one listed database becomes the instance connection. `null` enables detection. |
| `expose_services` | `true` | Expose detected services on the instance IP. |
| `previews` | `[]` | Named same-origin review paths with optional notes. |
| `processes` | `[]` | Named supervised argument lists. |
| `checks` | `[]` | Named shell-free commands run by `outpost:verify`. |
| `hooks` | `setup`, `verify`, and `teardown`: `[]` | Host-owned shell-free lifecycle commands. |
| `secrets` | `[]` | Host-owned env keys injected from the Keychain at boot, never written to the worktree. |
| `database` | `outpost` / `outpost` / `password` | Instance credentials. |
| `max_concurrent_provisions` | `3` | Instances allowed to boot and provision at once, per project; later ones wait for a slot. `0` removes the limit. |
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
