---
name: outpost-development
description: >
  Install, configure, and operate the Outpost package in Laravel applications
  to run branches as isolated container instances on Apple's container runtime.
license: MIT
metadata:
  author: Zack Warren
---

# Outpost

Use this skill when a Laravel application needs to integrate `zacksmash/outpost`: diagnosing setup and spinning up a branch as an isolated instance with its own container, services, and URL on macOS.

## Primary Goal

- apply the `zacksmash/outpost` package's public API in the smallest correct way

## Workflow

### 1. Confirm the environment

- macOS 26 or newer on Apple silicon with Apple `container` 1.2.x installed (`brew install container`)
- a Laravel application inside a git repository with at least one commit
- `mkcert` (`brew install mkcert`) when trusted local HTTPS is wanted

### 2. Install

```bash
composer require zacksmash/outpost --dev
php artisan vendor:publish --tag="outpost-config"   # optional; tag "outpost" publishes the same file
```

### 3. One-time host setup

```bash
php artisan outpost:install                # preferred guided setup; starts runtime and pulls image
container system start
# ~/.config/container/config.toml must set the machine's publication domain:
#   [dns]
#   domain = "outpost"
sudo container system dns create outpost   # Outpost prints this command but never runs sudo itself
container system stop && container system start
php artisan outpost:pull                   # pulls the exact configured version from GHCR
php artisan outpost:build                  # customized local fallback; first run takes minutes
php artisan outpost:doctor                 # read-only verification of the complete setup
php artisan outpost:certify                # create/trust the project wildcard certificate
```

The installer offers to start the runtime, pull a missing image, and create trusted HTTPS. Pass `--force` to apply runtime/image actions without prompting, `--https` to explicitly allow trust-store setup in a non-interactive run, or `--local` to build the configured image from package stubs instead of pulling it. It never invokes `sudo` itself, rewrites machine configuration, or changes application source files; it prints exact remedies for those steps instead.

`outpost:certify` invokes `mkcert -install`, which may ask for the macOS password, then stores a wildcard leaf certificate under `.outpost/tls`. With `https => auto`, new instances use trusted HTTPS when those files match the configured domain and otherwise fall back to HTTP.

If the machine already publishes under another domain, inspect the live value with `container system property list`, then set `OUTPOST_DOMAIN` to that domain instead of changing machine config. Editing `config.toml` does not affect the running service until it is restarted.

The doctor treats Apple `container` 1.2.x as verified. It reports older versions as blocking and newer unverified minors as warnings. It never changes host or runtime state and prints the command or file change for every failed check. If every check passes but one browser reports `ERR_ADDRESS_UNREACHABLE`, enable that browser under System Settings > Privacy & Security > Local Network, quit it fully, and reopen it.

### 4. Create and manage instances

```bash
php artisan outpost                        # prompt-driven: pick a branch, confirm a name
php artisan outpost feature/billing --name=billing --seed
php artisan outpost origin/review/invoices --name=invoices --open
php artisan outpost --pr=482 --name=pr-482 --open
php artisan outpost:doctor
php artisan outpost:pull --force
php artisan outpost:list                    # add --json for agent-readable inventory
php artisan outpost:info billing           # URLs, DSNs, credentials, runtime; add --json for agents
php artisan outpost:open billing           # starts the instance first when needed
php artisan outpost:open billing mailpit   # browser endpoints: app, mailpit, vite
php artisan outpost:start billing
php artisan outpost:stop billing
php artisan outpost:shell billing
php artisan outpost:exec billing -- php artisan test --filter=Feature
php artisan outpost:logs billing --follow
php artisan outpost:remove billing --force
```

- `outpost` accepts a local branch, a known remote branch, or a new local branch name. Remote branches are fetched and made editable; an existing local counterpart is preserved.
- `outpost --pr=<number>` fetches a GitHub pull request through `origin` into `outpost/pr-<number>`; use `--remote=<name>` for another configured remote. It cannot be combined with the branch argument.
- Other `outpost` options: `--name`, `--open`, `--seed`, `--mount-path-repos`
- commands taking a `name` argument prompt with a select when it is omitted
- `outpost:open` starts a stopped instance before opening its URL in the macOS default browser
- `outpost:info --json` is the stable machine-readable way for agents and scripts to discover instance and service endpoints
- `outpost:list --json` is the stable machine-readable inventory; `outpost:exec <name> -- <command...>` passes argument tokens without a shell, streams output, and preserves the inner exit code
- `outpost:remove` confirms before destroying; `--force` skips every confirmation and keeps the branch

### 5. Configure when detection needs help

Services are detected from the app's own configuration (database driver, redis usage across cache/session/queue/broadcast, smtp mailer). When detection guesses wrong, set `services` in `config/outpost.php` (e.g. `['mysql', 'redis']`) to skip detection, then remove and recreate the instance. The manifest at `.outpost/<name>/outpost.json` records what was detected.

Outpost uses Octane with Swoole automatically when the app exposes Octane configuration; otherwise it uses PHP-FPM. Set `server` to `fpm` to force the traditional request lifecycle or `octane` to require Octane. Octane runs behind nginx with websocket forwarding and polling-based live reload.

The `frontend` mode is `build` by default. Use `vite` to install dependencies and supervise the app's `dev` script with HMR, or `none` to skip npm. Vite mode requires a `package.json` `dev` script, explicitly allows only the generated instance hostname, and publishes the dev server there on `vite.port` (default `5173`). Set `vite.hot_file` when the app does not use `public/hot`.

By default detected services are reachable on the instance hostname using MySQL `3306`, PostgreSQL `5432`, Redis `6379`, Mailpit SMTP `1025`, and Mailpit UI `8025`. Instance IPs avoid host-port collisions; stateful services use the sandbox credentials. Set `expose_services` to `false` for loopback-only backing services.

Configure long-running Laravel processes as shell-free argument lists. `@php` resolves to the instance's selected PHP version. They wait for provisioning before starting, restart under Supervisor, and write to the normal instance logs:

```php
'processes' => [
    'queue' => ['@php', 'artisan', 'queue:work', '--sleep=1', '--tries=1'],
    'scheduler' => ['@php', 'artisan', 'schedule:work'],
    'horizon' => ['@php', 'artisan', 'horizon'],
],
```

Key `config/outpost.php` values: `domain` (default `outpost`), `image` (an exact versioned GHCR reference), `dns`, `path`, `resources` (4 CPUs and `2G` memory by default), `php` (versions baked into the image — rebuild locally after changing), `server` (`auto`, `fpm`, or `octane`), `frontend` (`build`, `vite`, or `none`), `vite`, `https` (`auto`, `true`, or `false`), `tls.path`, `services`, `expose_services`, `processes`, `database` (sandbox credentials baked into the image — rebuild locally after changing; letters, numbers, dots, dashes, underscores only), `timeout`.

## Rules, References, and Templates

Read before executing:

- `README.md` — full lifecycle, isolation model, and configuration reference
- `config/outpost.php` — every configurable value with documentation

## Examples

- A setup fails before instance creation: run `php artisan outpost:doctor`, apply the remedies attached to `FAIL` rows, and rerun it until only `PASS` or non-blocking `WARN` rows remain.
- A reviewer needs to try a GitHub pull request without disturbing their own branch: `php artisan outpost --pr=482 --name=pr-482 --open`, then `php artisan outpost:remove pr-482` when done.
- An agent needs database coordinates without parsing terminal tables: `php artisan outpost:info billing --json`, then read `endpoints.mysql.url`, `endpoints.pgsql.url`, or `endpoints.redis.url` when present.
- An agent needs to run a test without an interactive shell: `php artisan outpost:exec billing -- php artisan test --filter=Feature`, then use the command's unchanged exit code.
- An app on SQLite needs no services: the instance boots with nginx and PHP-FPM only, and Outpost creates `database/database.sqlite` automatically.
- A Redis queue needs a worker: add a `queue` process using `['@php', 'artisan', 'queue:work', '--sleep=1']`, recreate the instance, and inspect its output with `php artisan outpost:logs <name> --follow`.
- An Octane app should use the default `server => auto`; choose `fpm` only when testing the traditional request lifecycle. Use `frontend => vite` when edits need browser HMR and recreate the instance after changing either mode.
- The app installs a local package via a composer path repository: Outpost lists the path and asks before mounting it read-only; pass `--mount-path-repos` in scripts that must not prompt.

## Anti-patterns

- do not run instances for production parity; instances are development sandboxes with permissive sandbox credentials
- do not manually change runtime or DNS state before running `php artisan outpost:doctor`; it is read-only and reports the live state
- do not copy or commit `.outpost/tls/key.pem`; Outpost keeps the project-local leaf key inside the ignored `.outpost` directory and mounts it read-only
- do not assume direct backing-service access is network-isolated from every other local container; set `expose_services` to `false` when loopback-only services are required
- do not add `octane` or `vite` entries to `processes` when Outpost manages those modes; those names are reserved and their commands are generated automatically
- do not expect external Scout drivers to run inside instances; Horizon runs only when it is explicitly configured in `processes`
- do not put a shell command string or shell operators in `processes`; each command must be an argument array, with one item per argument
- do not edit files under `.outpost/<name>/runtime/` expecting Outpost to regenerate or validate them; they are written once at creation and applied verbatim on every boot
- do not document or rely on package internals (detector, provisioner, runtime classes); the supported surface is the artisan commands and `config/outpost.php`
