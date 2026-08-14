An agent should treat an outpost as a disposable execution environment attached to a dedicated Git branch—not as a place where work can safely disappear.

```
Agent
  ├─ edits .outpost/<name>/app          writable Git worktree
  ├─ uses outpost:* --json              discovery
  └─ uses outpost:exec                  commands/tests
                    │
                    ▼
          isolated Apple container VM
          ├─ app runtime
          ├─ disposable database
          └─ supervised processes
```

## Recommended agent workflow

Create a dedicated branch and instance:

```bash
php artisan outpost agent/task-482 \
    --name=agent-task-482 \
    --no-interaction
```

Discover state through stable JSON rather than parsing terminal output:

```bash
php artisan outpost:list --json
php artisan outpost:info agent-task-482 --json
```

The agent edits the dedicated worktree:

```
.outpost/agent-task-482/app
```

It runs commands using argument arrays through [ExecCommand.php](/Users/Zack/Dev/outpost/src/Console/Commands/ExecCommand.php:14):

```bash
php artisan outpost:exec agent-task-482 -- php artisan test
php artisan outpost:exec agent-task-482 -- php artisan migrate:fresh --seed
php artisan outpost:exec agent-task-482 -- npm run build
```

Everything after `--` is passed directly to `container exec`; no intermediate shell interprets pipes, substitutions, or redirects. Output streams back and the inner exit code is preserved.

Before removal, the agent must inspect and commit its work:

```bash
git -C .outpost/agent-task-482/app status
git -C .outpost/agent-task-482/app add --all
git -C .outpost/agent-task-482/app commit -m "Implement task 482"
```

Then:

```bash
php artisan outpost:remove agent-task-482 --force
```

`--force` retains the Git branch, but it does not preserve uncommitted work.

## Existing security boundaries

Outpost already provides several useful protections:

- Each instance has its own container VM, writable layer, services, IP address, CPU, and memory allocation.
- Only the instance worktree is mounted read-write at `/app`.
- The real application `.env` is never copied. A fresh environment is generated from `.env.example` with disposable credentials in [Provisioner.php](/Users/Zack/Dev/outpost/src/Provisioner.php:100).
- Home directories, `.ssh`, `.aws`, `.gnupg`, `~/Library`, and parent directories are prohibited as Composer path mounts.
- External Composer path repositories require confirmation, default to no, and mount read-only.
- `expose_services => false` keeps databases, Redis, and Mailpit on container loopback.
- Manifest validation prevents a modified manifest from targeting an unrelated container.
- Exact-host HTTPS certificates avoid wildcard trust.
- Lifecycle timeouts prevent agents from hanging indefinitely on unhealthy VMs.

For agent-created outposts, I would configure:

```php
'expose_services' => false,
```

and avoid `--mount-path-repos` unless the agent genuinely needs and is trusted to read those repositories.

## Important limits today

Outposts contain mistakes, but they are not yet a hostile-code sandbox:

- `/app` is deliberately writable, so a destructive command can erase uncommitted work in that outpost’s host worktree.
- `outpost:exec` currently runs with the container’s default user, which is root.
- Shell-free execution prevents shell injection; it does not make an inherently destructive command safe.
- Dependency installation executes Composer and npm scripts from the checked-out branch.
- Containers have outbound network access, so untrusted code could potentially transmit readable source.
- `outpost:remove --force` removes dirty worktrees without a final Git cleanliness check.
- `--forget` removes local bookkeeping while leaving the container behind and should only be used for a stuck runtime.

So the current model is good for trusted coding agents and accidental-damage containment. For a stronger package-level guarantee, the next hardening slice should add:

1. Non-root agent execution by default, with explicit `--root`.
2. A dirty-worktree refusal on removal, requiring `--discard-changes`.
3. An `agent` security profile that disables service exposure and path mounts.
4. Command audit logs and optional command policies.
5. Optional outbound-network restrictions.
6. Agent ownership, task metadata, and expiration in the manifest.
