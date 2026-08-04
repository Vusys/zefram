# CLAUDE.md

Architecture notes for an AI assistant working in this repo. This is a map, not a copy of
[#20](https://github.com/Vusys/zefram/issues/20) (the full design reference) — read that
issue first for the "why"; this file is for finding the "where" quickly.

## What this package does

Skips a PHPUnit test on rerun if every file it executed last time (source, test code,
config, anything read at runtime) still hashes the same, and it last passed. The skip
decision has to land *before* expensive setup (framework boot, `RefreshDatabase`, seeders),
so it's driven from a `setUp()` override, not a PHPUnit event hook (PHPUnit's event system
is read-only — a subscriber can observe a test but not veto it, and a `#[Before]` hook's
thrown skip gets swallowed and `setUp()` runs anyway).

## Where things live

| Concern | File |
|---|---|
| The one-line trait consumers add to their base `TestCase` | `src/ZeframAware.php` |
| Static bridge between the trait and the booted engine | `src/Zefram.php` |
| Core invalidation logic (fingerprint, skip decision, capture, pruning) | `src/Engine.php` |
| Global cache-bust fingerprint (composer.lock + phpunit.xml + PHP/package version + driver + watched paths) | `src/Fingerprint.php` |
| PHPUnit extension — registers event subscribers, wires the engine | `src/PhpUnit/ZeframExtension.php`, `src/PhpUnit/Subscriber/*` |
| Per-class JSON shard storage, atomic writes | `src/Store/Store.php`, `src/Store/TestRecord.php` |
| Coverage driver abstraction (pcov / null) | `src/Coverage/CoverageDriver.php`, `PcovDriver.php`, `NullDriver.php` |
| Content hashing (xxh128, mtime+size fast path) | `src/Hash/FileHasher.php` |
| `#[NeverSkip]` escape hatch | `src/Attribute/NeverSkip.php` |
| Paratest worker/mode detection | `src/Paratest.php` |
| `vendor/bin/zefram clear` / `stats` | `src/Console/*` |

## Invalidation model, in one paragraph

A test is skipped only if: (1) the global fingerprint matches — any change (a dependency
bump, a phpunit.xml edit, a watched-path edit) invalidates the *entire* cache, never
file-by-file; (2) the test has a stored record and its last result was `passed` — anything
else (failed/errored/risky/skipped) drops the record, so a failure can never get stuck
skipped; (3) every file in the test's own recorded path→hash map still hashes the same. Each
record pins its own file map (not a shared table) so a `--filter` rerun of one test can't
silently revalidate a sibling that never ran against the new code. Shards are self-contained
and written atomically, which is also what makes paratest's default `WrapperRunner` safe (one
worker owns one shard file per run).

## Running the suites

```bash
composer test       # fast unit suite — tests/Unit/, run on every save
composer test:e2e    # tests/E2E/ — spawns real child PHPUnit processes (dogfooding the
                     # package's own source, and a generated dependency graph); needs pcov,
                     # skips itself gracefully without it
composer check       # pint:check -> rector:check -> analyse (level 9, Larastan loaded),
                     # halts on first failure
```

## Further reading

- [#20](https://github.com/Vusys/zefram/issues/20) — full architecture/design reference:
  storage format, correctness guards, known blind spots and their escape hatches.
- [#36](https://github.com/Vusys/zefram/issues/36) — package-family conventions this repo
  converges on (toolchain, CI shape, docs site, badges).
- [CONTRIBUTING.md](CONTRIBUTING.md) — contributor quick-start and PR checklist.
