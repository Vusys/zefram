# Zefram

Tests running faster than light. Zefram records the set of files each PHPUnit test executes
and, on later runs, skips any test whose recorded files are all unchanged since it last
passed — before your framework boots, before the database refreshes.

!!! warning "Status: beta, pre-1.0"
    Tested end-to-end by its own e2e suite (dogfooding its source, a generated dependency
    graph, and a real testbench Laravel app) and trialled on a 6,029-test Laravel suite under
    paratest: a 5:20 full run became a 12s unchanged rerun, with no false skips from code
    coverage can see (see [#15](https://github.com/Vusys/zefram/issues/15)). The
    configuration can still change before 1.0.

## How it works

1. A trait in your base TestCase overrides `setUp()` and decides the skip before delegating
   to `parent::setUp()` — so a skipped test never boots Laravel, never touches the database.
   (A `#[Before]` hook can't do this: PHPUnit swallows a skip thrown there and runs `setUp()`
   anyway.)
2. If the test runs and passes, a PHPUnit extension records the files it executed (captured
   per-test via pcov) with content hashes, sharded per test class under `.zefram/`.
3. Next run, if every recorded file hashes the same — and the global fingerprint
   (composer.lock, phpunit config, PHP version, watched paths) matches — the test is skipped.

Failures are never cached: only a passing run can create a skip record, and any non-pass
drops it.

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

Requires PHP ≥ 8.2, PHPUnit ≥ 11.5, and [pcov](https://github.com/krakjoe/pcov) loaded with
`pcov.enabled=1`. Without it, Zefram disables itself, all tests run normally, and the summary
line says why.

Add the trait to your base TestCase:

```php
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use \Vusys\Zefram\ZeframAware;
}
```

If your TestCase defines its own `setUp()`, it takes precedence over the trait's — call the
helper first thing instead:

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

Including `vendor/vusys/zefram/phpstan-extension.neon` in your `phpstan.neon` flags an unguarded `tearDown()` like this.

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

Coverage-as-critical-path is a heuristic. It cannot see env vars, time, randomness, network,
or data read from files and databases at runtime. For tests with such dependencies:

- `#[\Vusys\Zefram\Attribute\NeverSkip]` on a test class or method.
- `neverSkip` parameter with fnmatch patterns:
  `<parameter name="neverSkip" value="App\Tests\Flaky\*"/>`.
- `watchedPaths` for file-shaped dependencies — any change to a matched file invalidates the
  entire cache. Patterns support `**` for recursive matching. Laravel users should always
  watch `config/**` (config files are visible to coverage only until `config:cache` makes
  them a blind spot — exactly the CI runs you want to accelerate), Blade *sources* (the
  compiled artifacts are deleted by `view:clear`), and `.env` plus `.env.testing` (dotenv is
  read with `file_get_contents`, which coverage can't see; Laravel falls back to `.env` when
  `.env.testing` doesn't exist). Also watch any data files the app reads at runtime, such as
  CSVs or JSON fixtures.
- `ZEFRAM=0` environment variable disables everything for a run.

Zefram also disables itself automatically when PHPUnit is collecting coverage
(`--coverage-*`), since both would contend for pcov and skipped tests would falsify the
report.

## Browser tests against a served app

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

## Further reading

- [CLI](cli.md) — `zefram clear` / `zefram stats`.
- [Development](development.md) — running this package's own test suites.
- [GitHub issue #20](https://github.com/Vusys/zefram/issues/20) — the full architecture and
  design reference: invalidation model, storage format, correctness guards, known blind
  spots. Deliberately not duplicated here — that issue is the single source of truth.
- [GitHub issue tracker](https://github.com/Vusys/zefram/issues) — everything else.
