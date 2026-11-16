# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Core engine (`Engine`, the `Zefram` static facade): records the set of files each PHPUnit
  test executes, via windowed pcov coverage, sharded per test class under `.zefram/`. Skips a
  test on rerun when every recorded file still hashes the same and it last passed.
- `ZeframAware` trait: drives the skip decision from a `setUp()` override, so a skipped test
  never boots the consuming framework (Laravel or otherwise) — proven necessary over a
  `#[Before]` hook, which PHPUnit's hook chain swallows a thrown skip from and runs `setUp()`
  anyway.
- `ZeframExtension` PHPUnit extension: computes the global cache-bust fingerprint
  (composer.lock, phpunit.xml, PHP version, package version, coverage driver, watched paths),
  records per-test outcomes (only a pass stores a record — anything else drops it, so a
  failure can never get stuck skipped), flushes dirty shards, and prints the summary line
  (`Zefram: skipped N of M tests (~Xs at sublight, warp factor Y)`) — the warp factor is a
  real computed speedup ratio, not flavor text.
- `#[NeverSkip]` attribute and `neverSkip` config glob patterns, plus `watchedPaths` for
  file-shaped dependencies coverage can't see on its own (env vars, `config:cache`d config,
  compiled Blade views), and a `ZEFRAM=0` kill switch.
- CLI (`bin/zefram`): `zefram clear` and `zefram stats [--json]`.
- Automatic pruning of stale cache state: fingerprint directories from a previous watched-path
  edit, and per-test records for tests deleted or renamed out of the suite — both scoped to
  full, unfiltered, non-parallel runs only, so a partial or parallel run never prunes state
  another process still needs.
- Paratest support: safe under paratest's default `WrapperRunner`, since self-contained
  per-class shards mean one worker owns one shard file per run. Worker detection via
  `TEST_TOKEN`, per-worker summary line suppressed (several concurrent workers printing one
  each would interleave and mangle the output), `--functional` mode documented as unsupported
  (undetectable from inside a worker process) with an honest risk assessment — the failure
  mode is a lost/overwritten record, never a false skip.
- Dogfood and generator-based end-to-end test harnesses: spawn real child PHPUnit processes
  against a copy of the package's own source, and against a generated synthetic dependency
  graph with a known edge list, proving skip/rerun correctness and invalidation precision
  against genuinely real (or precisely shaped) code rather than hand-verified assertions.
  Isolated into their own `composer test:e2e` suite, kept out of the fast default
  `composer test` path.
- `actions/cache` recipe for persisting `.zefram/` across GitHub Actions runs, documented in
  the README.
- Browser tests that drive a separately served app (Tetryon, Dusk, Panther) are never skipped
  (#80): the code serving their requests runs in a process pcov can't see, so their record
  would be incomplete. Other such suites are documented to use `neverSkip`.
- PHPUnit 13 support (`^11.5 || ^12.0 || ^13.0`), with PHPUnit 13 cells in CI on PHP 8.4.

[Unreleased]: https://github.com/Vusys/zefram/commits/main
