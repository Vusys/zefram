# Zefram Tests

[![Tests](https://github.com/Vusys/zefram/actions/workflows/tests.yml/badge.svg)](https://github.com/Vusys/zefram/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/Vusys/zefram/branch/main/graph/badge.svg)](https://codecov.io/gh/Vusys/zefram)
[![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)](composer.json)
[![PHPUnit](https://img.shields.io/badge/phpunit-%5E11.5%20%7C%7C%20%5E12.0%20%7C%7C%20%5E13.0-3b82f6)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

Tests running faster than light. Zefram records the set of files each PHPUnit test executes and, on later runs, skips any test whose recorded files are all unchanged since it last passed — before your framework boots, before the database refreshes.

> **Status: beta, pre-1.0.** Tested end-to-end by its own e2e suite (dogfooding its source, a generated dependency graph, and a real testbench Laravel app) and trialled on a 6,029-test Laravel suite under paratest: a 5:20 full run became a 12s unchanged rerun, with no false skips from code coverage can see (see [#15](https://github.com/Vusys/zefram/issues/15)). The configuration can still change before 1.0.

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

Requires PHP ≥ 8.2, PHPUnit ≥ 11.5, and [pcov](https://github.com/krakjoe/pcov) loaded with `pcov.enabled=1`. Without it, Zefram disables itself, all tests run normally, and the summary line says why.

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

A skipped test never reaches `parent::setUp()`, but PHPUnit still runs its `tearDown()`. If a `tearDown()` cleans up through the framework (a facade, `$this->app`), return early when zefram skipped the test, or it throws "A facade root has not been set":

```php
protected function tearDown(): void
{
    if ($this->zeframSkipped()) {
        parent::tearDown();

        return;
    }

    Storage::disk('data')->delete('fixture.csv');
    parent::tearDown();
}
```

The PHPStan extension (see below) flags an unguarded `tearDown()` like this.

Register the extension in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Vusys\Zefram\PhpUnit\ZeframExtension">
        <parameter name="enabled" value="true"/>
        <parameter name="cacheDirectory" value=".zefram"/>
        <parameter name="watchedPaths" value=".env,.env.testing,config/**,database/migrations/**,resources/views/**/*.blade.php"/>
    </bootstrap>
</extensions>
```

Configure pcov to instrument your whole project (its default misses `tests/`):

```ini
pcov.enabled = 1
pcov.directory = /path/to/project
pcov.exclude = "~/vendor/~"
```

Many PHP packages ship pcov with `pcov.enabled = 0`. Rather than editing `php.ini`, you can set it per run in a composer script:

```json
"scripts": {
    "test": "@php -d pcov.enabled=1 -d pcov.directory=. -d pcov.exclude=\"~/vendor/~\" vendor/bin/phpunit"
}
```

Add `.zefram/` to `.gitignore`. Each PHPUnit configuration file gets its own directory inside it (`.zefram/phpunit.xml-<hash>/`), so suites run from separate configs, such as a browser suite, can share one `cacheDirectory`.

### Laravel checklist

From trialling Zefram on real Laravel apps:

1. Add `ZeframAware` to `tests/TestCase.php`. Laravel's own `TestCase` defines `setUp()`, so if yours overrides it, call `$this->zeframSetUp()` first.
2. Guard any `tearDown()` that uses a facade or `$this->app` with `$this->zeframSkipped()` (see above). The PHPStan extension finds the unguarded ones.
3. Watch `.env`, `.env.testing`, `config/**`, `database/migrations/**`, your Blade (or other template) sources, and any data files read at runtime. In the trial, an unwatched `.env` was the only source of false skips.
4. Enable pcov for the run, with `pcov.directory` at the project root.
5. Browser tests that drive a served app (Tetryon, Dusk, Panther) run every time; see below. If they have their own PHPUnit config, it can share `.zefram/`.
6. Tests extending plain `PHPUnit\Framework\TestCase` without the trait always run. That's fine for fast unit tests.

## Escape hatches

Coverage-as-critical-path is a heuristic. It cannot see env vars, time, randomness, network, or data read from files and databases at runtime. For tests with such dependencies:

- `#[\Vusys\Zefram\Attribute\NeverSkip]` on a test class or method.
- `neverSkip` parameter with fnmatch patterns: `<parameter name="neverSkip" value="App\Tests\Flaky\*"/>`.
- `watchedPaths` for file-shaped dependencies — any change to a matched file invalidates the entire cache. Patterns support `**` for recursive matching. Laravel users should always watch `config/**` (config files are visible to coverage only until `config:cache` makes them a blind spot — exactly the CI runs you want to accelerate) Blade *sources* (the compiled artifacts are deleted by `view:clear`), and `.env` plus `.env.testing` (dotenv is read with `file_get_contents`, which coverage can't see; Laravel falls back to `.env` when `.env.testing` doesn't exist). Also watch any data files the app reads at runtime, such as CSVs or JSON fixtures.
- `ZEFRAM=0` environment variable disables everything for a run.

Zefram also disables itself automatically when PHPUnit is collecting coverage (`--coverage-*`), since both would contend for pcov and skipped tests would falsify the report.

### Browser tests against a served app

Browser tests that drive a separately served copy of the app aren't safe to skip. The
controllers, middleware and views that answer the browser's requests run in the server
process, which pcov in the test process never sees, so a change to them wouldn't invalidate
the test. Zefram never skips a test extending a known base of this kind:

- `Vusys\Tetryon\PHPUnit\BrowserTestCase` and `Vusys\Tetryon\Laravel\BrowserTestCase`
- `Laravel\Dusk\TestCase`
- `Symfony\Component\Panther\PantherTestCase`

For other out-of-process browser tests, add `neverSkip` for them
(`<parameter name="neverSkip" value="Tests\Browser\*"/>`), or leave the extension out of the
browser suite's PHPUnit config.

### Finding NeverSkip candidates automatically

Rather than discovering a blind spot the hard way (a false skip in production), opt into
`Vusys\Zefram\PHPStan\NeverSkipCandidateRule` — a PHPStan rule that flags test methods
calling known non-deterministic/runtime-only things (`rand()`, `time()`, `now()`, an un-faked
`Http::` call, raw `curl_exec()`, ...) without a `#[NeverSkip]` attribute already neutralizing
them. The same extension also loads `Vusys\Zefram\PHPStan\UnguardedTearDownRule`, which flags a
`tearDown()` that uses the framework before `parent::tearDown()` without checking
`$this->zeframSkipped()`. Add to your own `phpstan.neon`:

```neon
includes:
    - vendor/vusys/zefram/phpstan-extension.neon
```

Framework-agnostic checks always run; a handful of Laravel-aware checks (`now()`,
`Carbon::now()`, `Http::`, `Auth::user()`, `env()`) are name-matched the same way whether or
not Larastan is present in your project. It's a lint, not full dataflow analysis — a
preceding `$this->travel(...)`/`freezeTime()`/`Carbon::setTestNow(...)` (for time) or
`Http::fake()` (for HTTP) in the same method correctly neutralizes the calls after it, but it
doesn't know about your `watchedPaths` config — `#[NeverSkip]` is the only suppression it
understands.

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

Reads `.zefram/<config>/<fingerprint>/tests/*.json` shards directly — no PHPUnit run
involved — and reports the total tracked tests, how many are eligible to
skip next run, and the aggregate stored duration (the sum of every test's
last recorded run time — roughly how much wall-clock a full run would
recover from skipping). The fingerprint is recomputed the same way a real
run would right now, so this always reflects your current `composer.lock`/
`phpunit.xml`/PHP version, not a stale cache from before your last dependency
bump. `--json` emits the same data as structured JSON, for CI scripting. It reports on `phpunit.xml` (or `phpunit.xml.dist`) only; other configs' records are kept but not counted.

### Laravel

In a Laravel app, the same commands are also available as artisan commands — the package
service provider autodiscovers, no `config/app.php` edit needed:

```bash
php artisan zefram:clear
php artisan zefram:stats --json
```

These are thin wrappers over the same classes the standalone CLI calls, so behavior can't
drift between the two entry points. Purely optional DX — the package itself has no runtime
`illuminate/*` dependency; `Vusys\Zefram\Laravel\ZeframServiceProvider` only ever loads inside
an app that already has Laravel installed.

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

If you run several PHPUnit configs (say, a browser suite in `phpunit.browser.xml`), add each to `hashFiles(...)`; they share the cached `.zefram/`, one subdirectory per config.

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
