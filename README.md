# Zefram Tests

Tests running faster than light. Zefram records the set of files each PHPUnit test executes and, on later runs, skips any test whose recorded files are all unchanged since it last passed — before your framework boots, before the database refreshes.

> **Status: pre-alpha.** The core engine works and is unit-tested; the pcov capture layer and PHPUnit wiring are being validated against a real Laravel app. Not yet on Packagist. Several behaviors marked `TODO(spike #N)` in the source await confirmation from that spike.

## How it works

1. A trait in your base TestCase runs a skip check in a `#[Before(priority: PHP_INT_MAX)]` hook — ahead of `setUp()`, so a skipped test never boots Laravel, never touches the database.
2. If the test runs and passes, a PHPUnit extension records the files it executed (captured per-test via pcov) with content hashes, sharded per test class under `.zefram/`.
3. Next run, if every recorded file hashes the same — and the global fingerprint (composer.lock, phpunit config, PHP version, watched paths) matches — the test is skipped.

Failures are never cached: only a passing run can create a skip record, and any non-pass drops it.

## Installation

```bash
composer require --dev vusys/zefram-tests
```

Requires PHP ≥ 8.2, PHPUnit ≥ 11.5, and [pcov](https://github.com/krakjoe/pcov) (without it Zefram disables itself and all tests run normally).

Add the trait to your base TestCase:

```php
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use \Vusys\Zefram\ZeframAware;
}
```

Register the extension in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Vusys\Zefram\PhpUnit\ZeframExtension">
        <parameter name="enabled" value="true"/>
        <parameter name="cacheDirectory" value=".zefram"/>
        <parameter name="watchedPaths" value=".env.testing,config/*.php,database/migrations/*.php"/>
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
- `watchedPaths` for file-shaped dependencies — any change to a matched file invalidates the entire cache.
- `ZEFRAM=0` environment variable disables everything for a run.

Zefram also disables itself automatically when PHPUnit is collecting coverage (`--coverage-*`), since both would contend for pcov and skipped tests would falsify the report.

## Development

```bash
composer install
composer test
```
