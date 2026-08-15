# Contribution Guide

Thank you for considering contributing to Outpost! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all tests:

```bash
composer test
```

## Runtime Drivers

All lifecycle code depends on `Zacksmash\Outpost\Contracts\RuntimeDriver`. The service provider binds that contract to the existing `Runtime` Apple container implementation; Apple container remains the only supported driver.

Driver identifiers are stable lowercase values recorded in every manifest. Legacy manifests default to `apple-container`. A future driver must preserve the existing command behavior, preview URLs, image metadata contract, shell-free execution, worktree safety, upgrade recovery, and test coverage before it becomes selectable. Do not add runtime configuration, capability flags, or partial driver support before a second complete implementation exists.
