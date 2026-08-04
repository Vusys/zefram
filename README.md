# Zefram Tests

[![Tests](https://github.com/Vusys/zefram/actions/workflows/tests.yml/badge.svg)](https://github.com/Vusys/zefram/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)](composer.json)
[![PHPUnit](https://img.shields.io/badge/phpunit-%5E11.5%20%7C%7C%20%5E12.0-3b82f6)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

Tests running faster than light. Zefram records the set of files each PHPUnit test executes and, on later runs, skips any test whose recorded files are all unchanged since it last passed — before your framework boots, before the database refreshes.

> **Status: pre-alpha.** The core engine is unit-tested and the mechanics are validated against a real Laravel 12 app (see the M0 spike: capture overhead ~0.25ms/test, an 11× rerun speedup with a seeder-heavy suite skipped without touching the database). Not yet on Packagist; not yet integrated end-to-end.

## How it works

1. A trait in your base TestCase overrides `setUp()` and decides the skip before delegating to `parent::setUp()` — so a skipped test never boots Laravel, never touches the database. (A `#[Before]` hook can't do this: PHPUnit swallows a skip thrown there and runs `setUp()` anyway.)
2. If the test runs and passes, a PHPUnit extension records the files it executed (captured per-test via pcov) with content hashes, sharded per test class under `.zefram/`.
3. Next run, if every recorded file hashes the same — and the global fingerprint (composer.lock, phpunit config, PHP version, watched paths) matches — the test is skipped.

Failures are never cached: only a passing run can create a skip record, and any non-pass drops it.

At the end of a run, the extension prints a summary line:

```
Zefram: skipped 12 of 20 tests (~4.2s at sublight, warp factor 3.1)
```

The **warp factor** is a real computed ratio, not flavor text: (the stored duration of
every skipped test, from the last time it actually ran, plus the real measured duration of
every test that did run this pass) ÷ (the actual wall-clock time this pass took). A run with
nothing skipped sits around 1.0; heavy skipping pushes it up. It can also sit *below* 1.0 on
a suite whose tests are already near-instant — skipping a handful of microsecond tests can't
outrun fixed PHPUnit process/bootstrap overhead, and the metric says so honestly rather than
flattering itself.

## Installation

```bash
composer require --dev vusys/zefram
```

Requires PHP ≥ 8.2, PHPUnit ≥ 11.5, and [pcov](https://github.com/krakjoe/pcov) (without it Zefram disables itself and all tests run normally).

Add the trait to your base TestCase:

```php
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use \Vusys\Zefram\ZeframAware;
}
```

If your TestCase defines its own `setUp()`, it takes precedence over the trait's — call the helper first thing instead:

```php
protected function setUp(): void
{
    $this->zeframSetUp();
    parent::setUp();
    // your setup
}
```

Register the extension in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Vusys\Zefram\PhpUnit\ZeframExtension">
        <parameter name="enabled" value="true"/>
        <parameter name="cacheDirectory" value=".zefram"/>
        <parameter name="watchedPaths" value=".env.testing,config/**,database/migrations/**,resources/views/**/*.blade.php"/>
    </bootstrap>
</extensions>
```

Configure pcov to instrument your whole project (its default misses `tests/`):

```ini
pcov.enabled = 1
pcov.directory = /path/to/project
pcov.exclude = "~/vendor/~"
```

Add `.zefram/` to `.gitignore`.

## Escape hatches

Coverage-as-critical-path is a heuristic. It cannot see env vars, time, randomness, network, or data read from files and databases at runtime. For tests with such dependencies:

- `#[\Vusys\Zefram\Attribute\NeverSkip]` on a test class or method.
- `neverSkip` parameter with fnmatch patterns: `<parameter name="neverSkip" value="App\Tests\Flaky\*"/>`.
- `watchedPaths` for file-shaped dependencies — any change to a matched file invalidates the entire cache. Patterns support `**` for recursive matching. Laravel users should always watch `config/**` (config files are visible to coverage only until `config:cache` makes them a blind spot — exactly the CI runs you want to accelerate) and Blade *sources* (the compiled artifacts are deleted by `view:clear`).
- `ZEFRAM=0` environment variable disables everything for a run.

Zefram also disables itself automatically when PHPUnit is collecting coverage (`--coverage-*`), since both would contend for pcov and skipped tests would falsify the report.

## CLI

```bash
vendor/bin/zefram clear
```

Deletes the entire cache directory (reads `cacheDirectory` from your
`phpunit.xml`'s `ZeframExtension` parameter the same way the extension
itself does, defaulting to `.zefram`). Always clears everything — there's no
fingerprint-scoping argument, since stale fingerprint directories are
already pruned automatically during normal runs (any watched-path edit spins
up a fresh one, and the previous fingerprint's directory gets deleted the
next time you run your suite normally). A no-op, not an error, if the cache
directory doesn't exist yet.

```bash
vendor/bin/zefram stats
vendor/bin/zefram stats --json
```

Reads `.zefram/<fingerprint>/tests/*.json` shards directly — no PHPUnit run
involved — and reports the total tracked tests, how many are eligible to
skip next run, and the aggregate stored duration (the sum of every test's
last recorded run time — roughly how much wall-clock a full run would
recover from skipping). The fingerprint is recomputed the same way a real
run would right now, so this always reflects your current `composer.lock`/
`phpunit.xml`/PHP version, not a stale cache from before your last dependency
bump. `--json` emits the same data as structured JSON, for CI scripting.

## Paratest

Zefram works under [paratest](https://github.com/paratestphp/paratest)'s
default `WrapperRunner`, which distributes work per test class — exactly
what makes self-contained per-class shards (see How it works, above) safe
under parallelism: one worker owns one shard file per run, nothing to lock
or merge.

Every worker process only sees a partial slice of the suite, though, so a
worker:

- Never prunes stale fingerprint directories or stale per-test records —
  pruning based on a partial view would delete state other workers still
  need.
- Never prints its own `Zefram: skipped ...` summary line — with several
  concurrent workers each printing one, they'd interleave and mangle the
  terminal output.

Run `vendor/bin/zefram stats` (optionally `--json` for CI scripting) after
the full parallel run completes instead, to see the real aggregate numbers.

### `--functional` mode is unsupported

Paratest's `--functional` mode distributes work per test *method* instead
of per class, which breaks the one-worker-owns-one-shard assumption above:
two methods of the same class can land on different worker processes, each
independently writing the same shard file (last writer wins).

There's no reliable way to detect `--functional` from inside a worker
process to guard against this — it's a flag paratest keeps entirely on its
own orchestrator side and never forwards to a worker (not in the
`Configuration` object extensions receive, not as an environment variable,
not anywhere else observable; see the docblock on
`Vusys\Zefram\Paratest::isWorker()` for the investigation). So this isn't
guarded against at runtime — it's simply unsupported. Worth noting for risk
assessment: because shards are self-contained and written atomically (see
How it works, above), the actual failure mode is a lost/overwritten
*record*, not a false skip — a clobbered test just reruns next time rather
than being incorrectly cached. Still, avoid `--functional` with Zefram
enabled.

## CI

Without persisting `.zefram/` across runs, every CI run starts cold and the
package never gets to skip anything there — which is one of the highest-value
places to see savings, since CI usually re-runs the same passing tests over
and over on unchanged code. `actions/cache` on GitHub Actions:

```yaml
- name: Cache Zefram
  uses: actions/cache@v4
  with:
    path: .zefram
    key: zefram-${{ runner.os }}-php${{ matrix.php }}-${{ hashFiles('composer.lock', 'phpunit.xml') }}
    restore-keys: |
      zefram-${{ runner.os }}-php${{ matrix.php }}-

- name: Install dependencies
  run: composer install --no-interaction --prefer-dist

- name: Test
  run: composer test
```

Place the cache step before `composer install`, keyed on the PHP version
you're building (`matrix.php`, or hardcode it outside a matrix) plus a hash
of `composer.lock` and `phpunit.xml` — the same two file-content inputs that
feed the global fingerprint (see How it works, above; PHP version and
package version round out the fingerprint but don't need to be hashed here
since they're already baked into the key or don't change within a job). The
`restore-keys` fallback means a partial match (e.g. after a `composer.lock`
bump changes the exact key) still restores whatever's on the cache instead
of starting fully cold — the fingerprint model already isolates the stale
entries the restored cache brings along into their own directory, so they're
harmless, just unused until pruned automatically on the next full run (see
the CLI section's `clear` command note above for how that pruning works).

Under paratest (see Paratest, above), the cache step doesn't need to change —
`.zefram/` accumulates the same way regardless of how many workers wrote to
it, since each worker's shards are self-contained.

## Development

```bash
composer install
composer test       # fast unit suite — run this on every save
composer test:e2e   # slower end-to-end suite: spawns real child PHPUnit processes
                     # (dogfooding the package's own source, and a generated
                     # dependency graph) — run before pushing, or in a dedicated CI job
```

`composer test` runs `tests/Unit/`; `composer test:e2e` runs `tests/E2E/` (via a
separate `<testsuite>` in `phpunit.xml`, selected with `--testsuite e2e`). The
e2e suite needs [pcov](https://github.com/krakjoe/pcov) and skips itself
gracefully without it, same as the package.
