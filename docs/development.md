# Development

```bash
composer install
composer test       # fast unit suite — run this on every save
composer test:e2e   # slower end-to-end suite: spawns real child PHPUnit processes
                     # (dogfooding the package's own source, and a generated
                     # dependency graph) — run before pushing, or in a dedicated CI job
```

`composer test` runs `tests/Unit/`; `composer test:e2e` runs `tests/E2E/` (via a separate
`<testsuite>` in `phpunit.xml`, selected with `--testsuite e2e`). The e2e suite needs
[pcov](https://github.com/krakjoe/pcov) and skips itself gracefully without it, same as the
package.

```bash
composer check       # pint:check -> rector:check -> analyse, halts on first failure
composer infection    # mutation testing, full source (see infection.json5)
```

## CI

Without persisting `.zefram/` across runs, every CI run starts cold and the package never
gets to skip anything there — which is one of the highest-value places to see savings, since
CI usually re-runs the same passing tests over and over on unchanged code. `actions/cache` on
GitHub Actions:

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

Place the cache step before `composer install`, keyed on the PHP version you're building
(`matrix.php`, or hardcode it outside a matrix) plus a hash of `composer.lock` and
`phpunit.xml` — the same two file-content inputs that feed the global fingerprint. The
`restore-keys` fallback means a partial match (e.g. after a `composer.lock` bump changes the
exact key) still restores whatever's on the cache instead of starting fully cold — the
fingerprint model already isolates the stale entries the restored cache brings along into
their own directory, so they're harmless, just unused until pruned automatically on the next
full run.

## Docs site

```bash
pip install -r docs/requirements.txt
mkdocs build --strict
mkdocs serve   # live preview at http://127.0.0.1:8000
```

Run `mkdocs build --strict` locally before opening a PR that touches `docs/` — the docs
workflow only builds on push to `main`, not on pull requests, so a broken `--strict` build
won't be caught until after merge otherwise.

## Contributing

See [CONTRIBUTING.md](https://github.com/Vusys/zefram/blob/main/CONTRIBUTING.md) in the
repository root for the full contributor guide, and
[GitHub issue #20](https://github.com/Vusys/zefram/issues/20) for the architecture reference.
