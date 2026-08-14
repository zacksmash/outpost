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

It inspects the full setup, offers to start the Apple container service and pull the missing versioned base image, then prints the exact commands for anything requiring manual or privileged changes. `--force` applies those two safe actions without prompting. The installer never invokes `sudo`, rewrites machine configuration, or changes application source files.

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

Outpost has verified Apple's `container` 1.2.x line. Older versions fail the compatibility check; newer minors produce a warning rather than blocking you.

## Creating an Instance

The `outpost` command walks you through everything:

```bash
php artisan outpost
```

You'll pick a branch (or type a new name to create one), confirm the instance's name, and Outpost handles the rest: it checks out the branch into a dedicated worktree, detects which services the app needs, boots a container, writes the instance's `.env`, installs composer and npm dependencies, builds your front-end assets when a build script exists, runs your migrations, and prints the URL.

Everything can be provided up front when you'd rather not be asked:

```bash
php artisan outpost feature/billing --name=billing --seed
```

| Option | Description |
| --- | --- |
| `branch` | The branch the instance should run. Created if it doesn't exist. |
| `--name` | The instance name. Defaults to the branch name, slugged. |
| `--seed` | Seed the database after migrating. |
| `--mount-path-repos` | Mount composer path repositories without asking. |

### Service Detection

Outpost inspects your application's own configuration — the same sources `php artisan about` reads — to decide what each instance runs. A SQLite app needs no services at all. A `DB_CONNECTION=mysql` app gets MySQL with a database and credentials already provisioned. Redis appears whenever your cache, session, queue, or broadcasting uses it, and Mailpit appears when your mailer is SMTP.

When detection guesses wrong, set `services` in `config/outpost.php` to skip detection entirely, then remove and recreate the instance. Each instance's manifest at `.outpost/<name>/outpost.json` records what was detected, so you can always see exactly what an instance is running and why.

Octane, Horizon, and external Scout drivers are detected but not run inside instances. Outpost records them in the manifest and tells you at creation time — which means Redis-queued jobs will not process inside an instance. Honest limits beat silent ones.

## Day-to-Day

```bash
php artisan outpost:list             # every instance, its state, and its URL
php artisan outpost:doctor           # diagnose host, runtime, DNS, image, and app readiness
php artisan outpost:pull             # refresh the exact configured OCI image
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

The worktree is bind-mounted into the container, so the instance's code is editable right on your Mac — changes appear instantly, no sync step. The container gets its own IP address on Apple's container network, answers on port 80, and is reachable at `http://<name>-<app>.outpost`. No ports are published, so nothing collides with Herd, Sail, or anything else on your machine.

The instance's `.env` is seeded from your `.env.example` — never from your real `.env`, so real credentials stay out of sandboxes — and pointed at the instance's own services with the sandbox credentials from `config/outpost.php`. The published image contains the documented default credentials and PHP versions. Changing either requires a local `outpost:build` before creating more instances.

## Isolation and Its Limits

An instance is a real virtual machine boundary: a destructive command inside it can't touch your Mac. Two things deliberately cross that boundary, and both are visible:

- **The worktree** is mounted read-write at `/app`. That's the product — you're meant to edit the code.
- **Composer path repositories** outside the worktree can't resolve inside the container, so Outpost offers to mount them **read-only**, lists every path first, and defaults to *no*. Non-interactive runs mount nothing unless you pass `--mount-path-repos` explicitly. Read-only bounds destruction, not disclosure — that's why a human confirms.

Instances are development sandboxes, not production parity. The database accounts inside them are deliberately permissive, exactly like Sail's — but every service answers only on loopback inside its own container, so one instance can never reach another's database or cache across the container network.

## Configuration

| Key | Default | Description |
| --- | --- | --- |
| `domain` | `outpost` | The local domain instance URLs live on. |
| `image` | `ghcr.io/zacksmash/outpost:0.1.0` | Exact OCI image reference used by instances. |
| `dns` | `1.1.1.1` | Nameserver injected into builds and instances. |
| `path` | `.outpost` | Where instances live, relative to your project. |
| `php` | `['8.4', '8.5']` | PHP versions in the base image. |
| `services` | `null` | Set an array to skip service detection. |
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
