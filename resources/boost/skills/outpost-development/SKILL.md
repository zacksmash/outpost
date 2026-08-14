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

- macOS 26 or newer on Apple silicon with Apple `container` 1.2.x installed from its signed release package
- a Laravel application inside a git repository with at least one commit
- `mkcert` (`brew install mkcert`) when trusted local HTTPS is wanted

### 2. Install

```bash
composer require zacksmash/outpost --dev
php artisan vendor:publish --tag="outpost-config"   # optional; tag "outpost" publishes the same file
```

### 3. One-time host setup

```bash
php artisan outpost                        # preferred: offers setup once, then continues to instance creation
php artisan outpost:install                # optional: prepare the Mac without creating an instance
php artisan outpost:pull                   # pulls the exact configured version from GHCR
php artisan outpost:build                  # customized local fallback; first run takes minutes
php artisan outpost:doctor                 # read-only verification of the complete setup
php artisan outpost:certify                # prepare the trusted local certificate authority
```

The first interactive `outpost` run invokes setup automatically when needed. One consolidated confirmation can start Apple container, update `~/.config/container/config.toml` without replacing unrelated settings, restart the runtime, invoke Apple's administrator-protected resolver registration, prepare trusted HTTPS when the primary application uses it, and pull the image. Pass `--local` to `outpost:install` to build the configured image instead. Forced or non-interactive setup requires `--https` before Outpost may modify the trust store, and DNS registration must already exist unless setup is running in an attached administrator terminal. Application source files are never changed.

`outpost:certify` invokes `mkcert -install`, which may ask for the macOS password, and records that the configured domain is prepared. Each new HTTPS instance receives a leaf certificate for its exact hostname under `.outpost/<name>/runtime/tls`; no wildcard matching is used. With `https => auto`, Outpost mirrors the root URL Laravel generates and briefly probes the same hostname for a trusted HTTPS listener when that URL still uses HTTP. This catches secured Herd and Valet sites with a stale `APP_URL`. Set `OUTPOST_HTTPS=true` or `false` to override detection. A detected HTTPS app falls back to HTTP until certification is ready, and existing instances keep the scheme in their manifest until recreated.

If the machine should keep another publication domain, set `OUTPOST_DOMAIN` before setup. Otherwise, an approved setup plan changes the shared domain to `outpost` and restarts Apple container. Non-interactive `outpost` creation never attempts this setup implicitly; run `php artisan outpost:install --force` first, adding `--https` only when trust-store changes are explicitly allowed.

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
php artisan outpost:remove billing --forget --force # local cleanup when Apple's VM is stuck
```

- `outpost` accepts a local branch, a known remote branch, or a new local branch name. Remote branches are fetched and made editable; an existing local counterpart is preserved.
- `outpost --pr=<number>` fetches a GitHub pull request through `origin` into `outpost/pr-<number>`; use `--remote=<name>` for another configured remote. It cannot be combined with the branch argument.
- Other `outpost` options: `--name`, `--open`, `--seed`, `--mount-path-repos`
- commands taking a `name` argument prompt with a select when it is omitted
- `outpost:open` starts a stopped instance before opening its URL in the macOS default browser
- `outpost:info --json` is the stable machine-readable way for agents and scripts to discover instance and service endpoints
- `outpost:list --json` is the stable machine-readable inventory; `outpost:exec <name> -- <command...>` passes argument tokens without a shell, streams output, and preserves the inner exit code
- `outpost:remove` confirms before destroying; `--force` skips every confirmation and keeps the branch. Quick lifecycle calls stop after `lifecycle_timeout` seconds (30 by default); `--forget` deliberately removes only the local worktree and manifest when Apple's VM cannot be reached, leaving an orphaned container and printing its cleanup command.

### 5. Configure when detection needs help

Services are detected from the app's own configuration (database driver, redis usage across cache/session/queue/broadcast, smtp mailer). When detection guesses wrong, set `services` in `config/outpost.php` (e.g. `['mysql', 'redis']`) to skip detection, then remove and recreate the instance. The manifest at `.outpost/<name>/outpost.json` records what was detected.

Outpost uses Octane automatically when the app exposes Octane configuration; otherwise it uses PHP-FPM. `octane.server => auto` mirrors the app's `OCTANE_SERVER` and supports `swoole`, `roadrunner`, and `frankenphp`. Set `server` to `fpm` to force the traditional request lifecycle or `octane` to require Octane, and override `octane.server` only when an outpost should differ from the primary app. All three runtimes run behind nginx with websocket forwarding and live reload.

The image provides Swoole and pinned RoadRunner and FrankenPHP executables. RoadRunner apps must lock `spiral/roadrunner-http:^3.3`; the separate CLI downloader package is not required by Outpost. FrankenPHP embeds PHP 8.5, so the app must permit that version. Outpost validates both conditions before creating a worktree and records the resolved runtime as `octane_server` in the manifest.

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

Key `config/outpost.php` values: `domain` (default `outpost`), `image` (an exact versioned GHCR reference), `dns`, `path`, `resources` (4 CPUs and `2G` memory by default), `php` (versions baked into the image — rebuild locally after changing), `server` (`auto`, `fpm`, or `octane`), `octane.server` (`auto`, `swoole`, `roadrunner`, or `frankenphp`), `frontend` (`build`, `vite`, or `none`), `vite`, `https` (`auto` mirrors the primary app, `true` requires HTTPS, `false` requires HTTP), `tls.path`, `services`, `expose_services`, `processes`, `database` (sandbox credentials baked into the image — rebuild locally after changing; letters, numbers, dots, dashes, underscores only), `lifecycle_timeout` (30 seconds by default for bounded Apple container lifecycle operations), `timeout`.

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
- An Octane app should use the default `server => auto` and `octane.server => auto`; Outpost then mirrors `OCTANE_SERVER`. Choose `fpm` only when testing the traditional request lifecycle. Use `frontend => vite` when edits need browser HMR and recreate the instance after changing any mode.
- The app installs a local package via a composer path repository: Outpost lists the path and asks before mounting it read-only; pass `--mount-path-repos` in scripts that must not prompt.

## Anti-patterns

- do not run instances for production parity; instances are development sandboxes with permissive sandbox credentials
- do not manually change runtime or DNS state before trying interactive `php artisan outpost`; its one-time setup plan handles supported fixes, while `outpost:doctor` remains the read-only diagnostic path
- do not copy or commit `.outpost/<name>/runtime/tls/key.pem`; Outpost keeps each exact-host leaf key inside the ignored `.outpost` directory and mounts it read-only
- do not assume direct backing-service access is network-isolated from every other local container; set `expose_services` to `false` when loopback-only services are required
- do not add `octane` or `vite` entries to `processes` when Outpost manages those modes; those names are reserved and their commands are generated automatically
- do not add or download an app-local RoadRunner or FrankenPHP executable solely for Outpost; the base image supplies verified binaries, though an intentionally committed app-local `rr` remains authoritative
- do not expect external Scout drivers to run inside instances; Horizon runs only when it is explicitly configured in `processes`
- do not put a shell command string or shell operators in `processes`; each command must be an argument array, with one item per argument
- do not edit files under `.outpost/<name>/runtime/` expecting Outpost to regenerate or validate them; they are written once at creation and applied verbatim on every boot
- do not document or rely on package internals (detector, provisioner, runtime classes); the supported surface is the artisan commands and `config/outpost.php`
