<?php

declare(strict_types=1);

namespace Vusys\Zefram\Coverage;

/**
 * Windowed per-test capture via pcov. Requires pcov.directory to cover the project root
 * (including tests/) with pcov.exclude keeping vendor/ out — pcov's default directory
 * heuristic misses tests/.
 *
 * collect() is called with its default (\pcov\all): the inclusive/exclusive modes expect
 * an explicit filter list and return nothing without one. Per-test windowing via
 * clear() between tests keeps attribution from bleeding across consecutive tests.
 */
final class PcovDriver implements CoverageDriver
{
    public function name(): string
    {
        return 'pcov';
    }

    public function isAvailable(): bool
    {
        // Loaded but pcov.enabled=0 (the packaged default) collects nothing at all (#83).
        return extension_loaded('pcov') && filter_var(ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL);
    }

    public function start(): void
    {
        \pcov\start();
    }

    public function stop(): array
    {
        \pcov\stop();

        $coverage = \pcov\collect();

        \pcov\clear();

        $files = [];

        // collect() includes every instrumented file compiled during the window; keep
        // only files with at least one executed line.
        foreach ($coverage as $file => $lines) {
            foreach ($lines as $count) {
                if ($count > 0) {
                    $files[] = $file;

                    continue 2;
                }
            }
        }

        return $files;
    }
}
