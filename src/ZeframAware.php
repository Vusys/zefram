<?php

declare(strict_types=1);

namespace Vusys\Zefram;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Add to your base TestCase. The before-hook runs at maximum priority, which PHPUnit
 * places ahead of setUp() — so a skipped test never boots the framework, never touches
 * the database.
 */
trait ZeframAware
{
    #[Before(priority: PHP_INT_MAX)]
    protected function zeframBefore(): void
    {
        if (Zefram::shouldSkip(static::class, $this->name(), $this->dataName())) {
            $this->markTestSkipped('Zefram: unchanged since last pass');
        }

        Zefram::startCapture(static::class, $this->name(), $this->dataName());
    }

    // TODO(spike #2): confirm #[After] hooks run for tests skipped from a #[Before] hook;
    // the engine tolerates a missing stop either way.
    #[After(priority: PHP_INT_MIN)]
    protected function zeframAfter(): void
    {
        Zefram::stopCapture();
    }
}
