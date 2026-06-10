<?php

declare(strict_types=1);

namespace Vusys\Zefram\Coverage;

/**
 * Windowed per-test capture via pcov. Requires pcov.directory to cover the project root
 * (including tests/) with pcov.exclude keeping vendor/ out — pcov's default directory
 * heuristic misses tests/.
 *
 * TODO(spike #3): confirm windowing semantics — that collect()+clear() between tests
 * prevents attribution bleeding across consecutive tests, and what collect() returns
 * when start() was never called.
 */
final class PcovDriver implements CoverageDriver
{
    public function name(): string
    {
        return 'pcov';
    }

    public function isAvailable(): bool
    {
        return extension_loaded('pcov');
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

        return array_keys($coverage);
    }
}
