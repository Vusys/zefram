<?php

declare(strict_types=1);

namespace Vusys\Zefram;

use PHPUnit\Framework\Attributes\After;

/**
 * Add to your base TestCase. The skip decision is driven from a setUp() override — NOT a
 * #[Before] hook — because PHPUnit swallows a SkippedTest thrown in a before-hook, runs
 * the remaining before-chain (setUp() included, so the framework still boots), and only
 * rethrows the skip afterwards (PHPUnit 11.5 TestCase::invokeHookMethods()). A trait
 * method overrides the one inherited from the base class, so this setUp() supplants the
 * framework's; throwing here genuinely prevents the boot.
 *
 * If your TestCase defines its own setUp(), it wins over the trait's — call the helper
 * yourself, first thing:
 *
 *     protected function setUp(): void
 *     {
 *         $this->zeframSetUp();
 *         parent::setUp();
 *         // your setup
 *     }
 */
trait ZeframAware
{
    protected function setUp(): void
    {
        $this->zeframSetUp();

        parent::setUp();
    }

    /**
     * Skip if unchanged since the last pass; otherwise open the capture window. Runs
     * before parent::setUp() so the framework boot path is part of the recorded path.
     */
    protected function zeframSetUp(): void
    {
        if (Zefram::shouldSkip(static::class, $this->name(), $this->dataName())) {
            $this->markTestSkipped('Zefram: unchanged since last pass');
        }

        Zefram::startCapture(static::class, $this->name(), $this->dataName());
    }

    // After-hooks also run for tests skipped from setUp(); stopCapture() tolerates a
    // window that was never opened.
    #[After(PHP_INT_MIN)]
    protected function zeframAfter(): void
    {
        Zefram::stopCapture();
    }
}
