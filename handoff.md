# Handoff — Apple `container` sandbox and the `laravel-container` package

**Date:** 2026-08-13
**Machine:** macOS 27.0, Apple silicon, 128 GB RAM

## What exists

Two related things were built this session.

**`~/Dev/container`** — a LEMP sandbox (Ubuntu 24.04, nginx, PHP 8.4, MySQL 8) for
Apple's `container` runtime, driven by a Makefile. `make up` for an isolated box,
`make dev` to bind-mount `./public` for live editing. Its README documents the
two modes and the gotchas. Complete and working.

**`~/Dev/laravel-container`** — a Laravel package providing
`php artisan make:container <name>`: creates a git worktree, detects which services
the app needs from `composer.json`/`.env`, generates per-instance nginx and
supervisor configs, boots a shared base image with them mounted, provisions the
app, and prints `http://<instance>-<app>.box`. Nine artisan commands. **161 tests,
PHPStan 0 errors, Pint clean, 100% type coverage.**

Do not re-derive the design: read `docs/superpowers/specs/2026-08-13-laravel-container-instances-design.md`
(the binding authority) and `docs/superpowers/plans/2026-08-13-laravel-container-instances.md`.
Real-hardware evidence is in `docs/manual-verification.md`. Known non-blocking gaps
are catalogued in `docs/follow-ups.md` — check it before "discovering" anything.

## Immediate open item

Branch **`fix/path-repos-and-git-preconditions`** (4 commits, off `main` at `d275637`)
is complete and review-clean but **not merged**. I asked whether to merge and the
user asked for this handoff instead. That decision is still open.

`main` holds the 29-commit implementation. There is no git remote.

## State of the user's test app

`~/Dev/summit-test` is their live test app. **Off limits for destructive
operations.** It has the package installed via a path repository and hit the
path-repo bug below. It may still have an unremoved instance or a stale
`containers/` worktree. `container list --all` shows current containers; `lemp`
(their LEMP sandbox) and `buildkit` (the image builder) are not yours to delete.

## Environment facts that cost real time to learn

- **`container` is NOT part of macOS.** macOS ships the frameworks
  (Virtualization, Hypervisor, vmnet); the CLI is separate Apple open source,
  installed via `brew install container`, versioned independently (1.2.2 here).
- **DNS inside containers is broken by default on this machine.** Containers get
  the vmnet gateway `192.168.64.1` as nameserver and nothing answers there. Raw IP
  traffic works, so it presents as a *package* problem: `apt-get update` fails to
  resolve, then a cascade of misleading `Unable to locate package` errors. Fix is
  `--dns 1.1.1.1` on both `container build` and `container run`.
- **`.box` host resolution is independent of container-side DNS.** `domain = "box"`
  in `~/.config/container/config.toml` plus a one-time
  `sudo container system dns create box` makes `<name>.box` resolve *from the Mac*.
  That does NOT fix outbound DNS inside containers — both are needed together.
- **Recreating an instance reuses its name but gets a new IP**, and macOS caches
  the old answer. `dscacheutil -flushcache` is required.
- **`container stop` is idempotent** — verified empirically: exit 0 on an
  already-stopped container. Do not add `isRunning()` gates on that assumption's
  behalf.
- **Herd owns `.test`, 80, 443, and 3306.** Never take those. The LEMP sandbox uses
  8080/3307 on loopback; the package uses per-container IPs and no port publishing.

## Major decisions

Recorded in full during execution in an SDD ledger that has since been deleted
(git history is the record). Preserved here because they are not in any surviving
artifact.

**Product-level**

1. **Build it, don't publish yet.** The space is occupied — `container-use` has
   nearly the same pitch, DDEV covers containerised dev environments maturely — and
   Apple `container` at 1.2.2 ships breaking changes between minors. Use it against
   kudos/maxdb for a month; publish only if it beats DDEV for Laravel work.
2. **Scaffold with first-party `laravel package --commands`**, not a hand-rolled
   skeleton. Generated with `--vendor-namespace=Zack --class-name=Container` so the
   namespace matches what the plan already assumed. This replaced the original
   Task 1 entirely.
3. **v1 defers Octane and Horizon.** They are detected and recorded in the manifest
   but not started; everything is served by nginx + PHP-FPM. Consequence, documented
   in the README: Redis-queued jobs do not process in an instance.
4. **Postgres, Meilisearch, Mailpit, Reverb cut from v1** — no app on this machine
   uses them.

**Architectural**

5. **One container per instance, shared kitchen-sink base image.** Apple's tool has
   no compose equivalent, so a split design means hand-writing orchestration.
   Instance creation involves no image build.
6. **No port publishing.** Every container has its own IP, reached via `.box`.
7. **`/workspace` and instance code are never bind-mounted from `~/Dev`** in the
   sandbox — that would be a hole straight through the isolation. (The package's
   worktree mount at `/app` is the deliberate exception, since the user drives it.)
8. **Detection writes conclusions to an editable manifest** rather than re-deriving
   per boot, so a wrong guess is corrected by editing a file.
9. **Instance names are validated at the command boundary** — rejected unless
   `Naming::slug($name) === $name` **and non-empty**. `InstanceRepository` remains a
   dumb path builder; hardening it too is an open follow-up.
10. **A failed `stop` is non-fatal in `container:rm`**; `delete` is the step that
    must succeed. Intent of `rm` is "make this go away".
11. **A failed boot leaves the worktree in place** rather than auto-rolling back —
    it is the diagnostic evidence, and rollback that itself fails is worse.
12. **Path repositories are mounted read-only into the instance, behind an explicit
    confirmation that defaults to no** (branch `fix/path-repos-and-git-preconditions`).
    See the security note below — this decision has real teeth.

## Pitfalls — the valuable part

These are defects that shipped past tests, or process failures. Several recurred.

**The recurring one: "result discarded, success reported anyway."** This appeared
**four separate times** in different code — the container boot, `container:stop`/
`start`, the provisioning steps, and the worktree-add error message. Each printed
success or a bare error while throwing away the `ProcessResult` that explained what
happened. If you add a call through `ProcessRunner`, check its result and surface
`errorOutput`. Assume this pattern is still lurking somewhere unexamined; the last
known survivors are unchecked `mkdir()`/`file_put_contents()` calls.

**Green suites over dead or broken code:**

- **Nine tests silently never ran.** The scaffold's `phpunit.xml.dist` registers
  exactly three suites: `tests/ArchTest.php`, `tests/Feature`, `tests/Unit`. A test
  directory anywhere else is never executed and the suite still reports green.
  Caught only by comparing a reported count against the previous one.
- **`FakeProcessRunner` returns a SUCCESSFUL empty result for any unscripted
  command.** This makes failure paths invisible: a test that does not script the
  failing command proves nothing about the failure branch. It is also why the
  unchecked-boot-result bug survived.
- **`stateOf()` read the wrong column.** It took the last whitespace-delimited field
  of `container list --all` as the state, but the last field is the start timestamp
  — and `1024 MB` in the MEMORY column contains a space, skewing a naive split. The
  original test passed because its fixture was a contrived two-column string. Parse
  by header index and use realistic fixtures.
- **`dirname(__DIR__, 2)` became wrong** when the scaffold moved commands to
  `src/Console/Commands/`. The test asserted only the issued command string, so it
  could not tell depth 2 from depth 3. `BuildImageCommandTest` now pins it against a
  real `is_file($cwd.'/Dockerfile')`.

**Bugs only real execution found:**

- **Every instance returned HTTP 500.** `Provisioner` ran `key:generate` without
  ever creating a `.env` in the fresh worktree. Universal — every app, every service
  combination. No unit test could have caught it.
- **MySQL apps could not migrate.** The base image provisions `app`/`app`/`app` and
  `DatabaseConfig` hardcodes those in the manifest, but nothing wrote
  `DB_*`/`REDIS_*` into the instance's `.env`. Now written, overwriting in place.
- **Composer path repositories are unreachable inside the container.** The container
  mounts only the worktree and `/instance`. Any `"type":"path"` repository pointing
  at a host directory fails with `PathDownloader … Source path … is not found`.
  This is what the user hit in real use.
- **Cross-task drift the per-task reviews could not see:** detection selects PHP 8.3
  or 8.4, nginx and supervisor honour it, and `Provisioner` ran bare `php`/`composer`
  — resolving to whichever CLI `update-alternatives` picked. Only the whole-branch
  review saw it, because the inconsistency lived *between* tasks.

**Security:**

- **The empty string defeated the path-traversal guard.** The predicate was
  `slug($name) !== $name`; `slug('') === ''` satisfies it, and `worktreePath('')`
  returns the `containers/` directory itself. A task review claimed it had
  "independently proved the guard categorical" — that proof was false for `''` and
  was accepted. **Treat confident proofs in review output as claims.**
- **Read-only mounts bound destruction, not disclosure.** The path-repo mount list
  comes from `composer.json` *inside the worktree* — a file that an agent working in
  a previous instance of that branch can edit and commit. It could name `/Users/Zack`
  or `~/.ssh`, and a later `make:container` would mount it read-only into a new
  instance with outbound network. Hence the confirmation prompt defaulting to no,
  and `--no-interaction` failing closed. If you touch this code, do not widen
  eligibility without a human in the loop.

**Process failures worth knowing:**

- **A verification passed by accident.** The end-to-end task installed the package
  via a path repository and reported success — because its scratch app never
  *committed* the `composer.json` change, and a worktree checks out committed state.
  The user committed theirs and hit the bug immediately.
- **An implementer stopped mid-task** claiming it would "pick this back up
  automatically" after backgrounding a long build. It does not; the work sat
  uncommitted until checked. Verify state rather than trusting a status claim.
- **Test counts in task briefs go stale** as earlier tasks grow during fix rounds.
  They are not a compliance criterion.
- **I asserted `laravel new` had no package scaffolding.** It doesn't — but
  `laravel package` does, and I had only checked `laravel new --help`. Enumerate a
  CLI's commands before claiming a capability is absent.

## Suggested skills

- **`superpowers:systematic-debugging`** — before proposing any fix for a bug or
  unexpected behaviour. Several defects above were found by tracing rather than
  guessing; two were nearly mis-diagnosed by hypothesising instead of reproducing.
- **`superpowers:brainstorming`** — before any new feature or behaviour change.
  Required before implementation, including for changes that look bounded.
- **`superpowers:subagent-driven-development`** — if executing a written plan.
  Keep a ledger; the one for the completed work was deleted after merge and its
  rulings survive only in this document.
- **`superpowers:verification-before-completion`** — before claiming anything
  passes. Run the command and read the output.
- **The package ships Laravel's own skills** in `.agents/skills/`:
  `package-testing`, `package-compatibility`, `package-release`, `package-scaffold`.
  Use `package-testing` for Pest 4/5 + Testbench work, `package-compatibility` when
  touching the PHP/Laravel support matrix.
- **`superpowers:finishing-a-development-branch`** — for the open merge decision.

## Verifying anything

```bash
cd ~/Dev/laravel-container && composer test
```

Runs Pint, PHPStan (with Larastan and 99% type-coverage), then Pest. All three must
pass. `./vendor/bin/pint` fixes formatting. Every `src/` file must begin with
`declare(strict_types=1);` and every class constant must carry a type, both enforced.

The base image (`laravel-container-base`) is built and contains PHP 8.3.32, PHP
8.4.24, MySQL 8.0.46, Redis 7.0.15. **Rebuilding takes several minutes** — only
needed if something under `stubs/` changes.

## Not verified

`container:shell`, `container:logs`, `container:start` and `container:stop` have
never been invoked against a real container. The shell/logs TTY handoff runs outside
the `ProcessRunner` seam and cannot be faked. The mysql+redis path is proven against
a matching scratch app, not against kudos or maxdb themselves.
