<p align="center">
  <a href="https://github.com/pushery/legal-consent-for-laravel">
    <img src="https://raw.githubusercontent.com/pushery/legal-consent-for-laravel/main/art/header.png" alt="Legal Consent for Laravel" width="100%">
  </a>
</p>

# Legal Consent for Laravel

[![Latest Version](https://img.shields.io/packagist/v/pushery/legal-consent-for-laravel.svg)](https://packagist.org/packages/pushery/legal-consent-for-laravel)
[![PHP Version](https://img.shields.io/packagist/dependency-v/pushery/legal-consent-for-laravel/php.svg)](https://packagist.org/packages/pushery/legal-consent-for-laravel)
[![Laravel Versions](https://badge.laravel.cloud/badge/pushery/legal-consent-for-laravel?style=flat)](https://packagist.org/packages/pushery/legal-consent-for-laravel)
[![License](https://img.shields.io/packagist/l/pushery/legal-consent-for-laravel.svg)](LICENSE)

[![Tests](https://img.shields.io/badge/tests-Pest%205-8BC34A.svg)](https://pestphp.com)
![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen.svg)
![Type Coverage](https://img.shields.io/badge/types-100%25-brightgreen.svg)
[![PHPStan](https://img.shields.io/badge/PHPStan-max-blue.svg)](https://phpstan.org)
[![Code Style](https://img.shields.io/badge/code%20style-pint-orange.svg)](https://laravel.com/docs/pint)
![Databases](https://img.shields.io/badge/tested%20on-PostgreSQL%20%2B%20MySQL-336791.svg)
![Mutation](https://img.shields.io/badge/mutation-%E2%89%A580%25-blueviolet.svg)

Court-proof, versioned legal consent for Laravel. It is a **document-acceptance
ledger**: the package **renders and proves** your legal texts — it does not own them.

A registration does three legally distinct things — accepting a contract, taking notice of a
privacy notice, and giving a real consent — and treating them as one "I accept everything"
checkbox is a common, and real, GDPR violation. This package keeps them apart, and proves
acceptance the way the law requires (Art. 7(1); EDPB 05/2020 §108): it stores the **exact text
a user was shown**, its version and hash, and the server-side context — not just a timestamp.

## Installation

```bash
composer require pushery/legal-consent-for-laravel
```

PHP 8.4+ · Laravel 13 · SQLite, PostgreSQL, and MySQL 8.4 LTS — every database-touching path is
tested against a real PostgreSQL and a real MySQL, not just SQLite.

## What it does

- **Append-only audit ledger** — every acceptance, acknowledgment, and withdrawal is one
  immutable row with denormalized proof, hardened by a database trigger and an app-layer guard.
- **Versioned documents** — a SHA-256 hash detects a change; you classify how it must be
  communicated; only a core contract change forces active re-consent.
- **Four notice modes** — silent editorial, announced-but-never-blocking, deemed consent with an
  objection window (§ 308 Nr. 5 BGB), and a hard re-consent gate. The three that announce carry
  delivery proof; the silent one announces nothing, so there is nothing to prove.
- **Interchangeable content sources** — Markdown files, or an admin-maintained draft store
  reviewed per locale before a publish freezes it.
- **Fortify-optional** — three of the four ways to record consent replace Fortify's own flow: a
  trait, an event listener, or a headless JSON API. `laravel/fortify` is never required.
- **Optional, off by default** — a tamper-evidence hash chain, an Art. 8 age gate, and
  multi-tenancy scoping, each a single config switch.
- **Optional reactive UI** — plain Blade stubs by default; opt-in Livewire components and a
  WireKit-flavored variant, with no hard Livewire or Flux dependency.

## Documentation

**Full docs at [docs.pushery.com/legal-consent-for-laravel](https://docs.pushery.com/legal-consent-for-laravel/).**

- [Installation](https://docs.pushery.com/legal-consent-for-laravel/installation) — requirements,
  the publish tags, and the two groups that stay separate because publishing them unasked would
  destroy data.
- [Quick start](https://docs.pushery.com/legal-consent-for-laravel/quick-start) — write a text,
  publish a version, give a model a ledger, switch the gate on.
- [Recording consent](https://docs.pushery.com/legal-consent-for-laravel/recording-consent) — the
  four ways to write the ledger, the registration checklist, and the accept-time hash guard.
- [The four notice modes](https://docs.pushery.com/legal-consent-for-laravel/notice-modes/overview)
  — how a change is classified, announced, and only sometimes enforced, with a worked example for
  each of the three that announce.
- [Configuration reference](https://docs.pushery.com/legal-consent-for-laravel/reference/configuration)
  — every key, its default, and what it decides.
- [Testing your integration](https://docs.pushery.com/legal-consent-for-laravel/testing) —
  `Consent::fake()`, so your tests need none of this package's tables.

## Quality bar

Every change is held to Laravel Pint, Larastan at `max`, Rector, and a test suite at 100% line
and type coverage, plus a real-browser end-to-end suite and cross-engine tests against a real
PostgreSQL and MySQL 8.4 — the engines it runs on in production. That gate runs before every
release. Mutation testing runs on its own schedule and never gates a release: a score is a
measurement to act on, not a number to hold a version behind.

The suite is not part of the published package: the tests and their PHPUnit configuration stay in
the development repository, so `composer test` has nothing to run from an installed copy. See
[CONTRIBUTING.md](https://github.com/pushery/legal-consent-for-laravel/blob/main/CONTRIBUTING.md).

## Security

Please review the [security policy](SECURITY.md) and report vulnerabilities
privately rather than opening a public issue.

## Built by Pushery

This package is built and maintained by [Pushery](https://www.pushery.com) — a
Berlin-based studio building Laravel applications, SaaS products, and open-source
tools.

Building a Laravel UI? [WireKit](https://wirekit.app), Pushery's open-source
Livewire component kit, gives you a polished component library out of the box.
Browse the rest of our work at [pushery.com](https://www.pushery.com).

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
