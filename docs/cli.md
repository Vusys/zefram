# CLI

```bash
vendor/bin/zefram clear
```

Deletes the entire cache directory (reads `cacheDirectory` from your `phpunit.xml`'s
`ZeframExtension` parameter the same way the extension itself does, defaulting to
`.zefram`). Always clears everything — there's no fingerprint-scoping argument, since stale
fingerprint directories are already pruned automatically during normal runs (any watched-path
edit spins up a fresh one, and the previous fingerprint's directory gets deleted the next
time you run your suite normally). A no-op, not an error, if the cache directory doesn't
exist yet.

```bash
vendor/bin/zefram stats
vendor/bin/zefram stats --json
```

Reads `.zefram/<config>/<fingerprint>/tests/*.json` shards directly — no PHPUnit run involved — and
reports the total tracked tests, how many are eligible to skip next run, and the aggregate
stored duration (the sum of every test's last recorded run time — roughly how much
wall-clock a full run would recover from skipping). The fingerprint is recomputed the same
way a real run would right now, so this always reflects your current `composer.lock`/
`phpunit.xml`/PHP version, not a stale cache from before your last dependency bump. `--json`
emits the same data as structured JSON, for CI scripting.

## Laravel

In a Laravel app these are also available as artisan commands — `php artisan zefram:clear`
and `php artisan zefram:stats --json` — via an optional, autodiscovered service provider
that wraps the same underlying classes the standalone CLI calls. Purely optional DX; the
package itself has no runtime `illuminate/*` dependency.

Under [paratest](https://github.com/paratestphp/paratest), run `zefram stats` (optionally
`--json`) after the full parallel run completes to see the real aggregate numbers — a worker
process never prints its own summary line, to avoid interleaved output from several
concurrent workers. See
[GitHub issue #20](https://github.com/Vusys/zefram/issues/20) for the full paratest
compatibility notes, including why `--functional` mode is unsupported.
