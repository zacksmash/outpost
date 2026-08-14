<div align="center">
    <h1>Outpost</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/v/zacksmash/outpost.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/php-v/zacksmash/outpost.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://badge.laravel.cloud/badge/zacksmash/outpost"><img src="https://badge.laravel.cloud/badge/zacksmash/outpost?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/zacksmash/outpost/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/zacksmash/outpost/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/zacksmash/outpost"><img src="https://img.shields.io/packagist/dt/zacksmash/outpost.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Outpost spins up any branch of your Laravel application as an isolated instance with its own container, its own services, and its own URL — powered by Apple's [`container`](https://github.com/apple/container) runtime.

Think `git worktree add`, except the checkout also boots a working stack:

```bash
php artisan outpost feature/billing

# ⚡ The instance is ready: http://feature-billing-app.outpost
```

Each instance runs in its own lightweight virtual machine with exactly the services your application needs — MySQL, PostgreSQL, Redis, Mailpit — provisioned, migrated, and reachable in your browser. Your real app, your real database, and your real machine are untouched. Review a pull request while your own branch keeps running. Hand an AI agent a sandbox where a destructive command can't reach anything that matters.

## Requirements

- macOS 26 or newer on Apple silicon
- Apple's `container` CLI 1.2.x — `brew install container`
- A Laravel application in a git repository with at least one commit
- Optional trusted HTTPS: [`mkcert`](https://github.com/FiloSottile/mkcert) — `brew install mkcert`

## Installation

Install Outpost as a development dependency:

```bash
composer require zacksmash/outpost --dev
```

You may publish the configuration file if you'd like to customize anything:

```bash
php artisan vendor:publish --tag="outpost-config"
```

## One-Time Setup

Start with the guided installer:

```bash
php artisan outpost:install
```

It inspects the full setup, offers to start the Apple container service, pull the missing versioned base image, and create a trusted wildcard HTTPS certificate, then prints the exact commands for anything requiring manual or privileged changes. `--force` applies runtime and image actions without prompting but deliberately does not modify the system trust store; combine it with `--https` when that change is intentional. The installer never invokes `sudo` itself, rewrites machine configuration, or changes application source files.

The manual setup it guides you through is described below. First, make sure the container runtime is running:

```bash
container system start
```

Next, make instance URLs resolvable. The container runtime publishes every container's hostname under a **single machine-wide domain**, so that domain and Outpost's must agree. Point the runtime at `outpost` in `~/.config/container/config.toml`:

```toml
[dns]
domain = "outpost"
```

Then register the resolver — Outpost never runs `sudo` on your behalf, so this one is yours — and restart the runtime:

```bash
sudo container system dns create outpost
container system stop && container system start
```

Already publishing under another domain? Set `OUTPOST_DOMAIN` to match it instead. Outpost checks the running service's live domain at creation time—not just the config file—and tells you when a restart is still needed.

Finally, pull the versioned base image. Every instance boots from this single image, so creating an instance never waits on a build:

```bash
php artisan outpost:pull
```

The default image is `ghcr.io/zacksmash/outpost:0.1.0`, published for ARM64 from the same package stubs whenever a matching GitHub release is published. Exact tags keep an existing installation reproducible instead of silently changing underneath it.

Need different PHP versions or sandbox database credentials? Publish `config/outpost.php`, make the changes, and build the configured image locally instead. The first local build takes several minutes:

```bash
php artisan outpost:build

# Or perform the complete guided setup while choosing a local build:
php artisan outpost:install --local
```

Run the doctor at any point to inspect the host, live runtime, DNS publication and resolver state, base image, and application prerequisites without changing anything:

```bash
php artisan outpost:doctor
```

For trusted local HTTPS, install `mkcert` and let Outpost create one project-local certificate for the configured domain and every instance beneath it:

```bash
brew install mkcert
php artisan outpost:certify
```

`mkcert` may ask for your macOS password while installing its local certificate authority. Outpost keeps only the wildcard leaf certificate and key under `.outpost/tls`, mounts them read-only, redirects the application from HTTP to HTTPS, and uses the same trusted endpoint for Vite and Mailpit. With the default `https => auto`, a fresh installation falls back to HTTP until this command succeeds; changing the domain makes the old certificate ineligible automatically.

Outpost has verified Apple's `container` 1.2.x line. Older versions fail the compatibility check; newer minors produce a warning rather than blocking you.

## Creating an Instance

The `outpost` command walks you through everything:

```bash
php artisan outpost
```

You'll pick a branch (or type a new name to create one), confirm the instance's name, and Outpost handles the rest: it checks out the branch into a dedicated worktree, detects which services and web server the app needs, boots a container, writes the instance's `.env`, installs dependencies, prepares the front end, runs your migrations, and prints the URL.

Everything can be provided up front when you'd rather not be asked:

```bash
php artisan outpost feature/billing --name=billing --seed
php artisan outpost origin/review/invoices --name=invoices --open
php artisan outpost --pr=482 --name=pr-482 --open
```

Local branches, known remote branches, and new branch names all work in the same `branch` argument. When you choose a remote branch such as `origin/review/invoices`, Outpost fetches only that branch and creates a local tracking branch for the editable worktree. If the local branch already exists, Outpost preserves and uses it instead of resetting it.

For a GitHub pull request, `--pr=482` fetches GitHub's pull-request ref through `origin` into an editable `outpost/pr-482` branch. Use `--remote=upstream` when the pull request belongs to a different configured remote. An existing PR branch is preserved, so work committed inside a previous instance is never silently discarded.

| Option | Description |
| --- | --- |
| `branch` | Local branch, remote branch, or new local branch the instance should run. |
| `--name` | The instance name. Defaults to the branch name, slugged. |
| `--pr` | GitHub pull request number to fetch instead of a branch. |
| `--remote` | Git remote used with `--pr`; defaults to `origin`. |
| `--open` | Open the URL in the default browser when creation finishes. |
| `--seed` | Seed the database after migrating. |
| `--mount-path-repos` | Mount composer path repositories without asking. |

### Service Detection

Outpost inspects your application's own configuration — the same sources `php artisan about` reads — to decide what each instance runs. A SQLite app needs no services at all. A `DB_CONNECTION=mysql` app gets MySQL with a database and credentials already provisioned. Redis appears whenever your cache, session, queue, or broadcasting uses it, and Mailpit appears when your mailer is SMTP.

When detection guesses wrong, set `services` in `config/outpost.php` to skip detection entirely, then remove and recreate the instance. Each instance's manifest at `.outpost/<name>/outpost.json` records what was detected, so you can always see exactly what an instance is running and why.

Octane applications run automatically through Swoole and a websocket-aware nginx reverse proxy. Set `server` to `fpm` to use the traditional request lifecycle instead, or to `octane` to require Octane explicitly. The base image supplies Swoole and the polling file watcher Octane needs for live code reloads, so the consuming application does not need container-specific dependencies.

External Scout drivers are still reported as deferred. Horizon is reported as deferred unless you configure it as an application process. Outpost records every selected server, front-end mode, deferred capability, and supervised process in the manifest, so a Redis-backed queue never looks active when no worker is actually running.

### Service Access

Detected services are reachable from macOS on the instance hostname and their standard ports: MySQL `3306`, PostgreSQL `5432`, Redis `6379`, Mailpit SMTP `1025`, and the Mailpit UI `8025`. Each instance has its own private IP, so ten MySQL instances can all use `3306` without publishing or juggling host ports. MySQL, PostgreSQL, and Redis require the sandbox credentials from `config/outpost.php`; Mailpit and its SMTP listener contain disposable development mail.

Use one command to get copyable URLs, credentials, state, runtime, and process details:

```bash
php artisan outpost:info billing
php artisan outpost:info billing --json   # structured output for agents and scripts
php artisan outpost:open billing mailpit
```

Set `expose_services` to `false` when an application should keep every backing service on container loopback. The application URL and an enabled Vite endpoint remain reachable because they are the instance's public development surface.

### Front-end Workflow

The default `frontend` mode is `build`: Outpost installs npm dependencies and runs the application's `build` script once during provisioning. Set it to `vite` for a supervised Vite development server with HMR, or `none` to skip npm completely:

```php
'frontend' => 'vite',
```

Vite mode requires a `package.json` `dev` script. Outpost keeps Vite on container loopback, explicitly allows only the instance hostname, proxies its public `<scheme>://<instance>.outpost:5173` endpoint through nginx, writes that address to Laravel's hot file, enables polling for the macOS bind mount, and keeps the process alive with Supervisor. This gives HMR the same trusted scheme as the application and avoids mixed-content failures. Change `vite.port` or `vite.hot_file` when the application uses nonstandard values. The public Vite port lives on the instance's private IP, so it does not reserve a host port or collide with another instance.

### Application Processes

Queue workers, the scheduler, Horizon, and other long-running commands can run with the instance under Supervisor. Publish `config/outpost.php`, then define each command as a shell-free argument list:

```php
'processes' => [
    'queue' => ['@php', 'artisan', 'queue:work', '--sleep=1', '--tries=1'],
    'scheduler' => ['@php', 'artisan', 'schedule:work'],
    'horizon' => ['@php', 'artisan', 'horizon'],
],
```

`@php` resolves to the PHP version Outpost selected from the application's Composer constraint. Processes run from `/app` as the application user, send output to `outpost:logs`, and do not start until dependencies, the environment, and migrations are ready. Supervisor restarts them after crashes and normal container restarts. Argument arrays are passed directly without a shell; use one item per argument and do not use shell operators. The names `octane` and `vite` are reserved whenever Outpost is managing those processes.

## Day-to-Day

```bash
php artisan outpost:list             # every instance, its state, and its URL
php artisan outpost:info billing     # runtime details, URLs, DSNs, and credentials; --json available
php artisan outpost:doctor           # diagnose host, runtime, DNS, image, and app readiness
php artisan outpost:certify          # create or renew trusted local HTTPS; --force renews
php artisan outpost:pull             # refresh the exact configured OCI image
php artisan outpost:open billing     # start if needed, then open in the default browser
php artisan outpost:open billing mailpit # open Mailpit; `vite` is also supported
php artisan outpost:start billing    # start a stopped instance
php artisan outpost:stop billing     # stop it; worktree and data survive
php artisan outpost:shell billing    # open a shell inside the instance
php artisan outpost:logs billing     # show the service logs; --follow streams
php artisan outpost:remove billing   # remove the container, worktree, and data
```

If these checks pass but a Chromium-based browser reports `ERR_ADDRESS_UNREACHABLE`, allow that browser under **System Settings → Privacy & Security → Local Network**, quit it completely, and reopen it. macOS applies this permission per browser; another browser working does not imply every browser is allowed.

Stopping an instance preserves its database — the data lives in the container's own writable layer and survives across `stop` and `start`. Removing an instance destroys all of it, which is rather the point. Removal asks first, offers to delete the instance's branch when it's safe to do so, and `--force` skips every question (leaving the branch alone).

## How Instances Work

Instances live under `.outpost/` in your project root (Outpost adds it to your `.gitignore`). Each instance keeps three things there: the git worktree at `app/`, generated nginx and supervisord configuration at `runtime/`, and its manifest at `outpost.json`.

The worktree is bind-mounted into the container, so the instance's code is editable right on your Mac — changes appear instantly, no sync step. The container gets its own IP address on Apple's container network and is reachable at `<scheme>://<name>-<app>.outpost`. No ports are published onto macOS; web and service endpoints use standard ports on that unique IP, so nothing collides with Herd, Sail, or another instance.

The instance's `.env` is seeded from your `.env.example` — never from your real `.env`, so real credentials stay out of sandboxes — and pointed at the instance's own services with the sandbox credentials from `config/outpost.php`. The published image contains the documented default credentials and PHP versions. Changing either requires a local `outpost:build` before creating more instances.

## Isolation and Its Limits

An instance is a real virtual machine boundary: a destructive command inside it can't touch your Mac. Two things deliberately cross that boundary, and both are visible:

- **The worktree** is mounted read-write at `/app`. That's the product — you're meant to edit the code.
- **Composer path repositories** outside the worktree can't resolve inside the container, so Outpost offers to mount them **read-only**, lists every path first, and defaults to *no*. Non-interactive runs mount nothing unless you pass `--mount-path-repos` explicitly. Read-only bounds destruction, not disclosure — that's why a human confirms.

Instances are development sandboxes, not production parity. The database accounts inside them are deliberately permissive, exactly like Sail's. Direct service access binds selected ports to the instance network and protects MySQL, PostgreSQL, and Redis with the documented sandbox credentials; disable `expose_services` when network-level separation from other local containers matters more than host database-tool access.

## Configuration

| Key | Default | Description |
| --- | --- | --- |
| `domain` | `outpost` | The local domain instance URLs live on. |
| `image` | `ghcr.io/zacksmash/outpost:0.1.0` | Exact OCI image reference used by instances. |
| `dns` | `1.1.1.1` | Nameserver injected into builds and instances. |
| `path` | `.outpost` | Where instances live, relative to your project. |
| `php` | `['8.4', '8.5']` | PHP versions in the base image. |
| `server` | `auto` | Use Octane when installed, otherwise PHP-FPM; accepts `auto`, `fpm`, or `octane`. |
| `frontend` | `build` | Front-end workflow; accepts `build`, `vite`, or `none`. |
| `vite.port` | `5173` | Public per-instance port for the nginx-proxied Vite development server. |
| `vite.hot_file` | `public/hot` | Laravel Vite hot-file path, relative to the application. |
| `https` | `auto` | Use a trusted certificate when present; accepts `auto`, `true`, or `false`. |
| `tls.path` | `.outpost/tls` | Project-local wildcard certificate directory. |
| `services` | `null` | Set an array to skip service detection. |
| `expose_services` | `true` | Make detected services reachable on the instance hostname and standard ports. |
| `processes` | `[]` | Named, shell-free argument lists supervised with the instance. |
| `database` | `outpost` / `outpost` / `password` | Sandbox database credentials. |
| `timeout` | `60` | Seconds to wait for an instance to answer HTTP. |

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Outpost! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Zack Warren](https://github.com/zacksmash)
- [All Contributors](../../contributors)

## License

Outpost is open-sourced software licensed under the [MIT license](LICENSE.md).
