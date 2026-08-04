<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\E2E\Laravel;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Tests\Support\LaravelProjectRunner;

/**
 * See #20 for architecture context and #51 for what specifically needs proving here. The
 * only prior proof that ZeframAware composes correctly with a real Laravel TestCase was the
 * M0 spike against an now-unreferenced sample-project app — this replaces that with a real,
 * reproducible orchestra/testbench-backed harness in this package's own e2e suite. Spawns a
 * real child PHPUnit process (needed for pcov.directory isolation — see
 * LaravelProjectRunner's own docblock) — belongs in the isolated e2e suite (#3), not fast
 * `composer test`.
 */
final class TestbenchCompatibilityTest extends TestCase
{
    private LaravelProjectRunner $runner;

    protected function setUp(): void
    {
        if (! LaravelProjectRunner::pcovAvailable()) {
            $this->markTestSkipped('ext-pcov is not loaded; the Laravel e2e suite requires it, same as the package itself.');
        }

        $this->runner = new LaravelProjectRunner;
    }

    protected function tearDown(): void
    {
        if (isset($this->runner)) {
            $this->runner->destroy();
        }
    }

    /**
     * Covers #51's assertions 2 and 4: when not skipped, ZeframAware::setUp() must correctly
     * delegate to parent::setUp() -> Testbench's real app boot -> setUpTraits() ->
     * RefreshDatabase's hook (proved by a real migration + a real query succeeding, not just
     * an empty fresh process), and coverage capture must see the WarpCoreService file even
     * though it's reached through the container, not `new WarpCoreService()`.
     */
    public function test_cold_run_boots_the_real_laravel_app_runs_the_migration_and_passes(): void
    {
        $result = $this->runner->run();

        $this->assertSame(0, $result->exitCode, $result->stdout.$result->stderr);
        $this->assertSame([], $result->skipped(), 'nothing has a stored record yet, nothing should skip');
        $this->assertNotEmpty($result->passed());
        $this->assertSame(1, $this->runner->bootReachedCount(), 'the real Laravel app must have booted exactly once');

        $records = $this->runner->shardRecords();
        $this->assertNotEmpty($records, 'a passing test must have recorded a shard');

        $files = array_merge(...array_map(static fn (array $r): array => array_keys($r['files']), array_values($records)));
        $this->assertContains(
            'src/WarpCoreService.php',
            $files,
            'pcov must have captured the service class even though it was resolved through the Laravel container',
        );
    }

    /**
     * Covers #51's assertions 1 and 3: the actual value proposition for a Laravel app is
     * skipping *before* the database refreshes, not just before setUp() in the abstract.
     * bootReachedCount() staying at 1 (not growing to 2) proves defineEnvironment() — and
     * therefore RefreshDatabase's whole hook chain — never ran on the second pass.
     */
    public function test_rerun_skips_before_the_app_boots_or_the_database_refreshes(): void
    {
        $this->runner->run(); // cold: populate the record

        $rerun = $this->runner->run();

        $this->assertSame(0, $rerun->exitCode, $rerun->stdout.$rerun->stderr);
        $this->assertSame([], $rerun->passed(), 'nothing changed since the cold pass, nothing should actually execute');
        $this->assertNotEmpty($rerun->skipped());
        $this->assertSame(
            1,
            $this->runner->bootReachedCount(),
            'the app boot log must NOT have grown — a skipped test must never reach defineEnvironment(), let alone RefreshDatabase',
        );
    }
}
