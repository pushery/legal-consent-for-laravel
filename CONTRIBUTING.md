# Contributing

Thanks for considering a contribution — issues and pull requests are both welcome.

## Reporting an issue

Use the GitHub issue templates (bug report / feature request). Include the package
version and a minimal reproduction, and never paste secrets or credentials.

## Pull requests

- Keep the public API stable, or call out the break explicitly.
- Add tests for any behavior change.
- Add a `CHANGELOG.md` `## [Unreleased]` entry for anything a consumer can see.
- Update the bundled Boost skill (`resources/boost/skills/legal-consent-for-laravel/SKILL.md`) in
  the same change whenever the public API, the config surface, a command or a publish tag moves —
  it is adoption guidance that ships and is read from `vendor/`.
- The prose documentation lives in the documentation portal the README links to, not in this
  repository, and the README is a showcase rather than a second copy of it. Report a documentation
  defect in the issue tracker, or say so on the pull request, and it is fixed at the source.
- Keep each commit focused.

## Local requirements

**PHP 8.4.1 or newer** to work on the package, even though the package itself installs
on 8.4.0. The test toolchain (Pest 5 → `symfony/process`) is what raises the floor, so
on exactly 8.4.0 `composer install` fails with a message about `symfony/process` rather
than about Pest. Upgrade the patch version; nothing else is wrong.

## Quality bar

This package holds itself to a strict quality bar — Laravel Pint, Larastan at `max`,
Rector, and a test suite at 100% line and type coverage, plus a real-browser end-to-end
suite and cross-engine tests against real PostgreSQL and MySQL 8.4 (the engines it runs
on in production). The maintainers run that gate before every release. Mutation testing
runs on its own schedule and is deliberately not part of it. A pull request that keeps
the public API stable and ships tests for its change is easy to accept.
