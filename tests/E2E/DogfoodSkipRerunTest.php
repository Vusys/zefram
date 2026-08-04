<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Tests\Support\DogfoodRunner;

/**
 * See #20 for architecture context. Uses the dogfood harness (#2) against a real copy of
 * this package's own src/ + tests/Unit/. Spawns real child PHPUnit processes — one of the
 * slower tests in the suite, belongs in the isolated e2e suite (#3), not fast `composer test`.
 */
final class DogfoodSkipRerunTest extends TestCase
{
    private const MUTATED_FILE = 'src/Hash/FileHasher.php';

    private DogfoodRunner $runner;

    protected function setUp(): void
    {
        if (! DogfoodRunner::pcovAvailable()) {
            $this->markTestSkipped('ext-pcov is not loaded; the dogfood E2E suite requires it, same as the package itself.');
        }

        $this->runner = new DogfoodRunner;
    }

    protected function tearDown(): void
    {
        // setUp() may skip before $runner is assigned; tearDown() still runs.
        if (isset($this->runner)) {
            $this->runner->destroy();
        }
    }

    public function test_cold_run_executes_every_test(): void
    {
        $result = $this->runner->run();

        $this->assertSame(0, $result->exitCode, $result->stdout.$result->stderr);
        $this->assertSame([], $result->skipped(), 'nothing has a stored record yet, nothing should skip');
        $this->assertNotEmpty($result->passed());
        $this->assertStringContainsString('Zefram: skipped 0 of '.count($result->passed()), $result->stdout);
    }

    public function test_immediate_rerun_skips_every_test(): void
    {
        $cold = $this->runner->run();
        $everyTest = $cold->passed();
        sort($everyTest);

        $rerun = $this->runner->run();

        $this->assertSame(0, $rerun->exitCode, $rerun->stdout.$rerun->stderr);
        $this->assertSame([], $rerun->passed(), 'nothing changed since the cold pass, nothing should actually execute');

        $skipped = $rerun->skipped();
        sort($skipped);
        $this->assertSame($everyTest, $skipped, 'every test that passed cold must be the one skipped on rerun');
        $this->assertStringContainsString(
            sprintf('Zefram: skipped %d of %d', count($everyTest), count($everyTest)),
            $rerun->stdout,
        );
    }

    /**
     * Mutating a shared, widely-depended-on file (FileHasher.php: used directly by
     * FileHasherTest, and transitively by EngineTest through Engine) must rerun exactly the
     * tests recorded against it — determined from the cold run's own real coverage output,
     * not a hardcoded guess — and leave every unrelated test (e.g. StoreTest, which never
     * constructs a FileHasher) skipped.
     */
    public function test_mutating_a_shared_file_reruns_exactly_its_dependents_and_nothing_else(): void
    {
        $this->runner->run(); // cold: populate records
        $this->runner->run(); // stable: prove full-skip before touching anything

        $expectedToRerun = $this->runner->testsThatExecuted(self::MUTATED_FILE);
        sort($expectedToRerun);
        $this->assertNotEmpty($expectedToRerun, 'sanity: FileHasher.php must be recorded against at least one real test');

        $allIds = array_keys($this->runner->shardRecords());
        $expectedToStaySkipped = array_values(array_diff($allIds, $expectedToRerun));
        sort($expectedToStaySkipped);
        $this->assertNotEmpty($expectedToStaySkipped, 'sanity: at least one unrelated test must exist to prove selectivity');

        $this->runner->mutate(self::MUTATED_FILE, static fn (string $c): string => $c."\n// zefram e2e mutation: flips the hash, not the behavior\n");

        $result = $this->runner->run();

        $this->assertSame(0, $result->exitCode, $result->stdout.$result->stderr);

        $reran = $result->passed();
        sort($reran);
        $this->assertSame($expectedToRerun, $reran, 'exactly the tests recorded against the mutated file must rerun');

        $stillSkipped = $result->skipped();
        sort($stillSkipped);
        $this->assertSame($expectedToStaySkipped, $stillSkipped, 'tests that never touched the mutated file must stay skipped');
    }

    /**
     * Invalidation is not one-directional: reverting a previously-mutated file correctly
     * invalidates the cache again (the stored hash is still the mutated one, so it mismatches
     * the reverted content and reruns), and once that revert-run's fresh records are in place,
     * the suite stabilizes back to full-skip.
     */
    public function test_reverting_a_mutation_reinvalidates_then_stabilizes_back_to_full_skip(): void
    {
        $this->runner->run(); // cold
        $original = $this->runner->read(self::MUTATED_FILE);

        $expectedDependents = $this->runner->testsThatExecuted(self::MUTATED_FILE);
        sort($expectedDependents);

        $this->runner->mutate(self::MUTATED_FILE, static fn (string $c): string => $c."\n// zefram e2e mutation\n");
        $mutatedRun = $this->runner->run();
        $this->assertSame($expectedDependents, $this->sorted($mutatedRun->passed()), 'mutation reruns its dependents');

        $this->runner->write(self::MUTATED_FILE, $original);
        $revertRun = $this->runner->run();

        $this->assertSame(0, $revertRun->exitCode, $revertRun->stdout.$revertRun->stderr);
        $this->assertSame(
            $expectedDependents,
            $this->sorted($revertRun->passed()),
            'the stored hash still reflects the mutated content, so reverting must rerun the same dependents again — proves invalidation reacts to the file\'s current state each run, not just "has it ever changed"',
        );

        $stableRun = $this->runner->run();

        $this->assertSame(0, $stableRun->exitCode, $stableRun->stdout.$stableRun->stderr);
        $this->assertSame([], $stableRun->passed(), 'the file is genuinely back to its original content now, so the suite must fully stabilize back to skip');
        $this->assertNotEmpty($stableRun->skipped());
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
