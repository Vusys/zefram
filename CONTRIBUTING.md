# Contributing

## Quick start

```bash
composer install
composer test       # fast unit suite — run this on every save
composer test:e2e   # slower end-to-end suite: spawns real child PHPUnit processes
                     # (dogfooding the package's own source, and a generated
                     # dependency graph) — run before opening a PR
composer check       # pint:check -> rector:check -> analyse, halts on first failure
```

The e2e suite (and the package itself) needs [pcov](https://github.com/krakjoe/pcov); it
skips itself gracefully without it, same as `composer test:e2e`.

`brianium/paratest` isn't in `require-dev` — it can't install on PHP 8.2, this package's own
floor, so keeping it there would break `composer install` for every contributor on that PHP
version. Install it on demand to exercise the paratest path locally:

```bash
composer require --dev brianium/paratest
vendor/bin/paratest --configuration phpunit.xml
```

## Before opening a PR

- `composer check` and `composer test` must pass locally.
- `composer test:e2e` must pass locally if you touched anything under `src/` — CI runs it too,
  but it's slow enough that catching a failure locally first saves a round trip.
- If you touched `docs/`, run `mkdocs build --strict` locally — the docs workflow only builds
  on push to `main`, not on pull requests, so a broken `--strict` build won't show up in CI
  until after merge otherwise.

## Pre-1.0

Zefram is pre-1.0. Breaking changes are acceptable when called out clearly in the pull request
description and in the [changelog](CHANGELOG.md) — there's no deprecation cycle to honor yet.

## Changelog

Add an entry under the `## [Unreleased]` heading in [CHANGELOG.md](CHANGELOG.md), grouped
under `### Added` / `### Changed` / `### Fixed` / etc. per
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Describe the change from a
consumer's point of view, not the implementation — link back to the GitHub issue for the
"why" instead of duplicating it.

## Architecture

See [#20](https://github.com/Vusys/zefram/issues/20) for the full design reference
(invalidation model, storage format, correctness guards) and
[#36](https://github.com/Vusys/zefram/issues/36) for the package-family conventions this
repo follows. [CLAUDE.md](CLAUDE.md) is a shorter, code-navigation-oriented map of the same
ground.
