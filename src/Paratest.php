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

    /**
     * #13: --functional mode distributes per test *method* instead of per class, which
     * breaks the one-worker-owns-one-shard invariant isWorker() alone protects — two
     * methods of the same class could land on different worker *processes*, each with its
     * own in-memory Store, each independently flushing (last writer wins) the same shard
     * file on disk.
     *
     * Investigated (against paratest 7.20, WrapperRunner — the only runner this package's
     * paratest version has) whether that's detectable from inside a worker process: it is
     * not, reliably. --functional is a boolean that lives entirely in paratest's own
     * orchestrator-side Options object and is never forwarded to a worker in any observable
     * form:
     *   - The Configuration object ZeframExtension::bootstrap() receives
     *     (ApplicationForWrapperWorker::bootstrap(), built once per worker from the
     *     serialized --phpunit-argv) is byte-for-byte identical between functional and
     *     non-functional runs.
     *   - Env vars (fillEnvWithTokens(): PARATEST, TEST_TOKEN, UNIQUE_TEST_TOKEN) are set
     *     the same way regardless of the functional flag — confirmed empirically: a real
     *     --functional -p3 run sets TEST_TOKEN/PARATEST identically to a plain -p3 run.
     *   - The per-method vs. per-file distinction only shows up in the string a worker
     *     reads over its stdin protocol for each assigned unit of work (a bare file path vs.
     *     "$file\0$methodNameRegex"), which is consumed by paratest's own
     *     ApplicationForWrapperWorker::runTest() before ZeframExtension ever sees anything.
     *
     * Decided (per #13's own scope): don't chase an undetectable runtime signal. Documented
     * as unsupported in README.md's Paratest section instead, with an honest risk
     * assessment — the atomic-write + self-contained-shard storage format (#20) means the
     * actual failure mode is a lost/overwritten record (that test just reruns next time),
     * never a false skip.
     */
}
