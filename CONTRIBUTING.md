# Contributing

Thanks for considering a contribution. This package holds itself to a strict quality
bar, and every change is expected to keep all of the gates green.

## Getting started

```bash
git clone git@github.com:pushery/legal-consent-for-laravel.git
cd legal-consent-for-laravel
composer install
just setup   # one-time: wires the release-gate git hook
```

## Quality gates

All of the following must pass. The aggregate static + test gate is:

```bash
composer qa
```

which runs, and each can be run on its own:

| Command | Gate |
|---|---|
| `composer format:test` | Code style — Laravel Pint, zero diffs (`composer format` to fix). |
| `composer rector:test` | Refactoring — Rector with the PHP rule set, dry-run clean (`composer rector` to apply). |
| `composer analyse` | Static analysis — Larastan at `max` level, no errors. |
| `composer test:type-coverage` | 100% type coverage of `src/`. |
| `composer test:coverage` | 100% line coverage of `src/`. |

The suite uses [Pest](https://pestphp.com) and Orchestra Testbench.

The full local gate — including the real-browser end-to-end suite and mutation
testing — is `just all`. It runs on **your machine**, not GitHub Actions (a private
package should not burn Actions minutes on every push). A pre-push hook, wired once
by `just setup`, blocks a push to `main` unless `just all` last passed on exactly
that commit. Emergency bypass: `git push --no-verify`.

## Pull request expectations

- Keep `composer qa` green.
- Add tests for behavior changes.
- Update `README.md` and `CHANGELOG.md` (`## [Unreleased]`) when behavior or
  configuration changes.
- Keep commits focused and the public API stable, or call out the break explicitly.
