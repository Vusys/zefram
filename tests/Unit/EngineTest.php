<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Attribute\NeverSkip;
use Vusys\Zefram\Config;
use Vusys\Zefram\Engine;
use Vusys\Zefram\Hash\FileHasher;
use Vusys\Zefram\Store\Store;
use Vusys\Zefram\Store\TestRecord;
use Vusys\Zefram\Tests\Support\FakeDriver;
use Vusys\Zefram\Tests\Support\TempProject;

final class EngineTest extends TestCase
{
    private const TEST_CLASS = 'App\\Tests\\WarpCoreTest';

    private TempProject $project;

    private FakeDriver $driver;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->write('src/WarpCore.php', '<?php class WarpCore {}');
        $this->project->write('tests/WarpCoreTest.php', '<?php class WarpCoreTest {}');

        $this->driver = new FakeDriver([
            $this->project->path('src/WarpCore.php'),
            $this->project->path('tests/WarpCoreTest.php'),
            $this->project->path('vendor/laravel/framework/src/Foundation.php'),
            '/usr/share/elsewhere/outside-root.php',
        ]);
    }

    protected function tearDown(): void
    {
        // setUp() may throw before $project is assigned (e.g. a test skipped upstream of
        // TempProject construction) — tearDown() still runs, so guard the typed property.
        if (isset($this->project)) {
            $this->project->destroy();
        }
    }

    public function testFirstRunNeverSkips(): void
    {
        $this->assertFalse($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
    }

    public function testSkipsUnchangedTestThatPreviouslyPassed(): void
    {
        $this->runAndPass('testEngage');

        $this->assertTrue($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
    }

    public function testRerunsWhenAnExecutedFileChanges(): void
    {
        $this->runAndPass('testEngage');

        $this->project->write('src/WarpCore.php', '<?php class WarpCore { public int $dilithium = 9; }');

        $this->assertFalse($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
    }

    public function testRerunsWhenAnExecutedFileIsDeleted(): void
    {
        $this->runAndPass('testEngage');

        unlink($this->project->path('src/WarpCore.php'));

        $this->assertFalse($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
    }

    public function testVendorAndOutsideRootFilesAreNotTracked(): void
    {
        $this->runAndPass('testEngage');

        // vendor file never existed on disk and outside-root file is unreadable: if either
        // were tracked, the hash check would fail and the test would not skip
        $this->assertTrue($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
    }

    public function testFailureDropsTheRecord(): void
    {
        $this->runAndPass('testEngage');

        $engine = $this->engine();
        $this->assertTrue($engine->shouldSkip(self::TEST_CLASS, 'testEngage', ''));

        $engine2 = $this->engine();
        $engine2->startCapture(self::TEST_CLASS, 'testEngage', '');
        $engine2->stopCapture();
        $engine2->recordNotPassed(self::TEST_CLASS, 'testEngage', '');
        $engine2->finish();

        $this->assertFalse($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
    }

    public function testForeignSkipDropsTheRecordButSelfSkipKeepsIt(): void
    {
        $this->runAndPass('testEngage');

        // self skip: shouldSkip() returned true, then the Skipped event arrives
        $engine = $this->engine();
        $this->assertTrue($engine->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
        $engine->recordSkipped(self::TEST_CLASS, 'testEngage', '');
        $engine->finish();

        $this->assertTrue($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''), 'self-skip must preserve the record');

        // foreign skip: someone else's markTestSkipped, no shouldSkip() approval this run
        $engine = $this->engine();
        $engine->recordSkipped(self::TEST_CLASS, 'testEngage', '');
        $engine->finish();

        $this->assertFalse($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''), 'foreign skip must drop the record');
    }

    public function testDataSetsAreTrackedIndependently(): void
    {
        $this->runAndPass('testBeam', 'two to beam up');

        $engine = $this->engine();

        $this->assertTrue($engine->shouldSkip(self::TEST_CLASS, 'testBeam', 'two to beam up'));
        $this->assertFalse($engine->shouldSkip(self::TEST_CLASS, 'testBeam', 'three to beam up'));
        $this->assertFalse($engine->shouldSkip(self::TEST_CLASS, 'testBeam', 0));
    }

    public function testPassedWithoutCaptureStoresNothing(): void
    {
        $engine = $this->engine();
        $engine->recordPassed(self::TEST_CLASS, 'testEngage', '');
        $engine->finish();

        $this->assertFalse($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
    }

    public function testOutcomeBeforeAfterHookClosesTheCaptureWindow(): void
    {
        // PHPUnit may emit Passed before the #[After] hook runs; recordPassed must close
        // the still-open window itself rather than lose the capture
        $engine = $this->engine();
        $engine->shouldSkip(self::TEST_CLASS, 'testEngage', '');
        $engine->startCapture(self::TEST_CLASS, 'testEngage', '');
        $engine->recordPassed(self::TEST_CLASS, 'testEngage', '');
        $engine->finish();

        $this->assertSame(1, $this->driver->stops);
        $this->assertTrue($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
    }

    public function testDisabledEngineNeverSkipsAndNeverCaptures(): void
    {
        $this->runAndPass('testEngage');

        $engine = $this->engine(enabled: false);
        $startsBefore = $this->driver->starts;

        $this->assertFalse($engine->shouldSkip(self::TEST_CLASS, 'testEngage', ''));

        $engine->startCapture(self::TEST_CLASS, 'testEngage', '');

        $this->assertSame($startsBefore, $this->driver->starts);
    }

    public function testNeverSkipConfigPatternWins(): void
    {
        $this->runAndPass('testEngage');

        $engine = $this->engine(neverSkip: ['App\\Tests\\WarpCoreTest::*']);

        $this->assertFalse($engine->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
    }

    public function testNeverSkipAttributeWins(): void
    {
        $class = NeverSkipFixture::class;

        $engine = $this->engine();
        $engine->shouldSkip($class, 'testVolatile', '');
        $engine->startCapture($class, 'testVolatile', '');
        $engine->stopCapture();
        $engine->recordPassed($class, 'testVolatile', '');
        $engine->finish();

        $this->assertFalse($this->engine()->shouldSkip($class, 'testVolatile', ''));
    }

    public function testSummaryReportsSkips(): void
    {
        $this->runAndPass('testEngage');

        $engine = $this->engine();
        $engine->shouldSkip(self::TEST_CLASS, 'testEngage', '');
        $summary = $engine->finish();

        $this->assertNotNull($summary);
        $this->assertStringContainsString('skipped 1 of 1', $summary);
        $this->assertMatchesRegularExpression('/warp factor \d+\.\d/', $summary);
    }

    public function testWarpFactorComputesRatioOfEstimatedFullRunToActualElapsed(): void
    {
        $config = new Config(enabled: true, projectRoot: $this->project->root);
        $hasher = new FileHasher();

        // seed a known "last recorded duration" for a test that will be skipped this pass
        $store = new Store($config->cachePath() . '/fp');
        $store->put(self::TEST_CLASS, 'testEngage', new TestRecord(
            files: [
                'src/WarpCore.php' => $hasher->hash($this->project->path('src/WarpCore.php')),
                'tests/WarpCoreTest.php' => $hasher->hash($this->project->path('tests/WarpCoreTest.php')),
            ],
            duration: 4.0,
            result: TestRecord::RESULT_PASSED,
        ));
        $store->flush();

        // deterministic clock: construction sees t=0, warpFactor() sees t=10s later
        $times = [0, 10_000_000_000];
        $i = 0;
        $clock = static function () use (&$times, &$i): int {
            return $times[$i++] ?? end($times);
        };

        $engine = new Engine($config, $this->driver, new Store($config->cachePath() . '/fp'), new FileHasher(), 'fp', $clock);

        $this->assertTrue($engine->shouldSkip(self::TEST_CLASS, 'testEngage', ''), 'sanity: the seeded record must actually be skippable');

        // (4.0s stored duration for the one skipped test + 0s for anything that actually
        // ran) / 10s elapsed = 0.4
        $this->assertEqualsWithDelta(0.4, $engine->warpFactor(), 0.0001);
    }

    public function testWarpFactorIsOneWhenElapsedTimeIsZero(): void
    {
        $clock = static fn (): int => 5_000_000_000; // constant: construction and warpFactor() see the same instant
        $config = new Config(enabled: true, projectRoot: $this->project->root);

        $engine = new Engine($config, $this->driver, new Store($config->cachePath() . '/fp'), new FileHasher(), 'fp', $clock);

        $this->assertSame(1.0, $engine->warpFactor());
    }

    public function testIsFullRunReflectsConfig(): void
    {
        $this->assertFalse($this->engine(fullRun: false)->isFullRun());
        $this->assertTrue($this->engine(fullRun: true)->isFullRun());
    }

    public function testFullRunPrunesStaleFingerprintDirectoriesOnFinish(): void
    {
        $this->runAndPass('testEngage'); // populates .zefram/fp on disk

        // a stale fingerprint directory left behind by an earlier config change (see #6)
        $this->project->write('.zefram/stale-fingerprint/tests/placeholder.json', '{}');

        $this->engine(fullRun: true)->finish();

        $this->assertDirectoryDoesNotExist($this->project->path('.zefram/stale-fingerprint'));
        $this->assertDirectoryExists($this->project->path('.zefram/fp'), 'the current fingerprint must survive its own prune');
    }

    public function testFullRunPrunesRecordsForTestsThatNoLongerExist(): void
    {
        $this->runAndPass('testEngage');
        $this->runAndPass('testBeam', 'two to beam up'); // simulates a method later renamed/deleted

        // a full-run pass that only observes testEngage — testBeam is never seen (ran or
        // skipped) this time, exactly as if it had been removed from the class
        $engine = $this->engine(fullRun: true);
        $engine->shouldSkip(self::TEST_CLASS, 'testEngage', '');
        $engine->finish();

        $this->assertTrue($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''), 'still-observed test keeps its record');
        $this->assertFalse($this->engine()->shouldSkip(self::TEST_CLASS, 'testBeam', 'two to beam up'), 'the vanished method\'s record must have been pruned');
    }

    public function testNonFullRunNeverPrunesStaleRecords(): void
    {
        $this->runAndPass('testEngage');
        $this->runAndPass('testBeam', 'two to beam up');

        // filtered/grouped/paratest run: only testEngage observed, but fullRun is false
        $engine = $this->engine(fullRun: false);
        $engine->shouldSkip(self::TEST_CLASS, 'testEngage', '');
        $engine->finish();

        $this->assertTrue(
            $this->engine()->shouldSkip(self::TEST_CLASS, 'testBeam', 'two to beam up'),
            'a non-full-run pass must never prune records for tests it simply did not touch',
        );
    }

    public function testNonFullRunNeverPrunesFingerprintDirectories(): void
    {
        $this->project->write('.zefram/stale-fingerprint/tests/placeholder.json', '{}');

        $this->engine(fullRun: false)->finish();

        $this->assertDirectoryExists($this->project->path('.zefram/stale-fingerprint'), 'a filtered/grouped/paratest run must never prune');
    }

    public function testDisabledEngineNeverPrunesEvenOnAFullRun(): void
    {
        $this->project->write('.zefram/stale-fingerprint/tests/placeholder.json', '{}');

        $this->engine(enabled: false, fullRun: true)->finish();

        $this->assertDirectoryExists($this->project->path('.zefram/stale-fingerprint'));
    }

    public function testIsParatestWorkerReflectsConfig(): void
    {
        $this->assertFalse($this->engine()->isParatestWorker());
        $this->assertTrue($this->engine(paratestWorker: true)->isParatestWorker());
    }

    public function testIsFullRunIsFalseUnderParatestEvenWhenOtherwiseAFullRun(): void
    {
        // a structural guarantee (#11): paratestWorker forces isFullRun() false regardless
        // of what fullRun alone says, so pruning never trusts a caller to have already
        // combined the two conditions correctly.
        $this->assertFalse($this->engine(fullRun: true, paratestWorker: true)->isFullRun());
        $this->assertTrue($this->engine(fullRun: true, paratestWorker: false)->isFullRun());
    }

    public function testParatestWorkerNeverPrunesEvenOnAnOtherwiseFullRun(): void
    {
        $this->project->write('.zefram/stale-fingerprint/tests/placeholder.json', '{}');

        $this->engine(fullRun: true, paratestWorker: true)->finish();

        $this->assertDirectoryExists(
            $this->project->path('.zefram/stale-fingerprint'),
            'a paratest worker only sees a partial view of the suite and must never prune',
        );
    }

    private function runAndPass(string $method, int|string $dataName = ''): void
    {
        $engine = $this->engine();

        $this->assertFalse($engine->shouldSkip(self::TEST_CLASS, $method, $dataName));

        $engine->startCapture(self::TEST_CLASS, $method, $dataName);
        $engine->stopCapture();
        $engine->recordPassed(self::TEST_CLASS, $method, $dataName);
        $engine->finish();
    }

    /**
     * @param list<string> $neverSkip
     */
    private function engine(bool $enabled = true, array $neverSkip = [], bool $fullRun = false, bool $paratestWorker = false): Engine
    {
        $config = new Config(
            enabled: $enabled,
            projectRoot: $this->project->root,
            neverSkip: $neverSkip,
            fullRun: $fullRun,
            paratestWorker: $paratestWorker,
        );

        // fresh store + hasher per engine simulates a new process; fixed fingerprint keeps
        // the cache namespace stable across the simulated runs
        return new Engine($config, $this->driver, new Store($config->cachePath() . '/fp'), new FileHasher(), 'fp');
    }
}

#[NeverSkip]
final class NeverSkipFixture
{
}
