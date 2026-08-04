<?php

declare(strict_types=1);

namespace Vusys\Zefram;

/**
 * Paratest's default WrapperRunner distributes work per test class, which is exactly what
 * makes self-contained per-class shards (see #20) safe under parallelism: one worker owns
 * one shard file per run. But a worker only ever sees a partial view of the whole suite, so
 * anything that assumes a full-suite view — pruning (#6, #7), the per-worker summary line
 * (#12) — must know to stay out of the way. TEST_TOKEN is the env var paratest sets in every
 * worker process it spawns; a small dedicated helper (rather than an inline getenv() check
 * scattered across call sites) keeps the detection in one directly-testable place.
 */
final class Paratest
{
    public static function isWorker(): bool
    {
        return getenv('TEST_TOKEN') !== false;
    }
}
