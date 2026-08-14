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

Use this skill when a Laravel application needs to integrate `zacksmash/outpost`: spinning up a branch as an isolated instance with its own container, services, and URL on macOS.

## Primary Goal

- apply the `zacksmash/outpost` package's public API in the smallest correct way

## Workflow

### 1. Confirm the environment

- macOS on Apple silicon with the `container` CLI installed (`brew install container`)
- a Laravel application inside a git repository with at least one commit

### 2. Install

```bash
composer require zacksmash/outpost --dev
php artisan vendor:publish --tag="outpost-config"   # optional; tag "outpost" publishes the same file
```

### 3. One-time host setup

```bash
container system start
sudo container system dns create outpost   # Outpost prints this command but never runs sudo itself
php artisan outpost:build                  # builds the shared base image; first run takes minutes
```

### 4. Create and manage instances

```bash
php artisan outpost                        # prompt-driven: pick a branch, confirm a name
php artisan outpost feature/billing --name=billing --seed
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

- A reviewer needs to try a pull request without disturbing their own branch: `php artisan outpost pr-branch`, open the printed `http://<name>-<app>.outpost` URL, then `php artisan outpost:remove <name>` when done.
- An app on SQLite needs no services: the instance boots with nginx and PHP-FPM only, and Outpost creates `database/database.sqlite` automatically.
- The app installs a local package via a composer path repository: Outpost lists the path and asks before mounting it read-only; pass `--mount-path-repos` in scripts that must not prompt.

## Anti-patterns

- do not run instances for production parity; instances are development sandboxes with permissive sandbox credentials
- do not expect Octane, Horizon, or external Scout drivers to run inside instances — they are detected, recorded in the manifest as deferred, and skipped, so Redis-queued jobs do not process inside an instance
- do not edit files under `.outpost/<name>/runtime/` expecting Outpost to regenerate or validate them; they are written once at creation and applied verbatim on every boot
- do not document or rely on package internals (detector, provisioner, runtime classes); the supported surface is the artisan commands and `config/outpost.php`
