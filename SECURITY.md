# Security Policy

Zefram is a single-maintainer, pre-1.0 project. There is no formal support matrix — the
latest release on the default branch is the only version that receives fixes.

## Reporting a vulnerability

Preferred: use GitHub's private vulnerability reporting for this repository —
<https://github.com/Vusys/zefram/security/advisories/new>. This keeps the report private
until a fix is available and lets you track the response through GitHub.

If you can't use that (e.g. you don't have a GitHub account), email
**bryan@vuii.co.uk** with as much detail as you can: affected version, a reproduction case,
and the impact you believe it has.

Please don't open a public issue for a suspected vulnerability.

## Scope

**In scope:**

- `src/` — the runtime: coverage capture, fingerprinting, storage, the PHPUnit extension and
  trait.
- Anything that writes to `.zefram/` — path handling in particular, since the cache directory
  and watched-path patterns are derived from user-supplied config.
- `.github/` — secret-leak surface in this repo's own CI workflows.

**Out of scope:**

- PHPUnit itself — report upstream at
  [sebastianbergmann/phpunit](https://github.com/sebastianbergmann/phpunit).
- pcov itself — report upstream at [krakjoe/pcov](https://github.com/krakjoe/pcov).
- Composer dependencies — report to the dependency's own project.
- The codebase under test — Zefram observes what a test suite executes; it doesn't vouch for
  the security of that code.

## What counts as a security issue here

Zefram's core risk surface is narrow: it reads project files to hash them, and it writes a
JSON cache under a configurable directory. Concretely in scope: path traversal via
`cacheDirectory` or `watchedPaths` config, a way to make Zefram write outside the intended
cache root, or a way to make it falsely report a test as passed/skipped that didn't actually
run correctly (a correctness bug with security-relevant consequences, e.g. skipping a test
that would have caught a real vulnerability). General correctness bugs without a security
angle belong in a regular [GitHub issue](https://github.com/Vusys/zefram/issues) instead.
