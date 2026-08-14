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

### 2. Install

```bash
composer require zacksmash/outpost --dev
php artisan vendor:publish --tag="outpost-config"   # optional; tag "outpost" publishes the same file
```

### 3. One-time host setup

```bash
container system start
# ~/.config/container/config.toml must set the machine's publication domain:
#   [dns]
#   domain = "outpost"
sudo container system dns create outpost   # Outpost prints this command but never runs sudo itself
container system stop && container system start
php artisan outpost:build                  # builds the shared base image; first run takes minutes
php artisan outpost:doctor                 # read-only verification of the complete setup
```

If the machine already publishes under another domain, inspect the live value with `container system property list`, then set `OUTPOST_DOMAIN` to that domain instead of changing machine config. Editing `config.toml` does not affect the running service until it is restarted.

The doctor treats Apple `container` 1.2.x as verified. It reports older versions as blocking and newer unverified minors as warnings. It never changes host or runtime state and prints the command or file change for every failed check. If every check passes but one browser reports `ERR_ADDRESS_UNREACHABLE`, enable that browser under System Settings > Privacy & Security > Local Network, quit it fully, and reopen it.

### 4. Create and manage instances

```bash
php artisan outpost                        # prompt-driven: pick a branch, confirm a name
php artisan outpost feature/billing --name=billing --seed
php artisan outpost:doctor
php artisan outpost:list
php artisan outpost:start billing
php artisan outpost:stop billing
php artisan outpost:shell billing
php artisan outpost:logs billing --follow
php artisan outpost:remove billing --force
```

- `outpost` options: `branch` argument (created if missing), `--name`, `--seed`, `--mount-path-repos`
- commands taking a `name` argument prompt with a select when it is omitted
- `outpost:remove` confirms before destroying; `--force` skips every confirmation and keeps the branch

### 5. Configure when detection needs help

Services are detected from the app's own configuration (database driver, redis usage across cache/session/queue/broadcast, smtp mailer). When detection guesses wrong, set `services` in `config/outpost.php` (e.g. `['mysql', 'redis']`) to skip detection, then remove and recreate the instance. The manifest at `.outpost/<name>/outpost.json` records what was detected.

Key `config/outpost.php` values: `domain` (default `outpost`), `image`, `dns`, `path`, `php` (versions baked into the image — rebuild after changing), `services`, `database` (sandbox credentials baked into the image at build time — rebuild after changing; letters, numbers, dots, dashes, underscores only), `timeout`.

## Rules, References, and Templates

Read before executing:

- `README.md` — full lifecycle, isolation model, and configuration reference
- `config/outpost.php` — every configurable value with documentation

## Examples

- A setup fails before instance creation: run `php artisan outpost:doctor`, apply the remedies attached to `FAIL` rows, and rerun it until only `PASS` or non-blocking `WARN` rows remain.
- A reviewer needs to try a pull request without disturbing their own branch: `php artisan outpost pr-branch`, open the printed `http://<name>-<app>.outpost` URL, then `php artisan outpost:remove <name>` when done.
- An app on SQLite needs no services: the instance boots with nginx and PHP-FPM only, and Outpost creates `database/database.sqlite` automatically.
- The app installs a local package via a composer path repository: Outpost lists the path and asks before mounting it read-only; pass `--mount-path-repos` in scripts that must not prompt.

## Anti-patterns

- do not run instances for production parity; instances are development sandboxes with permissive sandbox credentials
- do not manually change runtime or DNS state before running `php artisan outpost:doctor`; it is read-only and reports the live state
- do not expect Octane, Horizon, or external Scout drivers to run inside instances — they are detected, recorded in the manifest as deferred, and skipped, so Redis-queued jobs do not process inside an instance
- do not edit files under `.outpost/<name>/runtime/` expecting Outpost to regenerate or validate them; they are written once at creation and applied verbatim on every boot
- do not document or rely on package internals (detector, provisioner, runtime classes); the supported surface is the artisan commands and `config/outpost.php`
