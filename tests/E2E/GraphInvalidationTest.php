<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Tests\Support\GeneratedProjectRunner;
use Vusys\Zefram\Tests\Support\GraphGenerator;

/**
 * See #20 for architecture context. The generator-based complement to #22's dogfood test:
 * #22 proves correctness against real package code, this proves precision against a graph
 * shape chosen to be unambiguous — the expected rerun set is computed from the known edge
 * list, not verified by hand. Spawns real child PHPUnit processes — belongs in the isolated
 * e2e suite (#3), not fast `composer test`.
 *
 * Graph shape: a shared leaf with a diamond built on top of it, plus an unrelated node
 * running in artificial-expensive-setup mode (stands in for a seeder-heavy test):
 *
 *     Leaf -> B -> D
 *     Leaf -> C -> D
 *     Expensive (independent, ~0.3s of real usleep'd setup per execution)
 */
final class GraphInvalidationTest extends TestCase
{
    private const EXPENSIVE_MICROSECONDS = 300_000;

    private ?GraphGenerator $graph = null;

    private ?GeneratedProjectRunner $runner = null;

    protected function setUp(): void
    {
        if (!GeneratedProjectRunner::pcovAvailable()) {
            $this->markTestSkipped('ext-pcov is not loaded; the generator E2E suite requires it, same as the package itself.');
        }

        $this->graph = GraphGenerator::fromEdges([
            'Leaf' => [],
            'B' => ['Leaf'],
            'C' => ['Leaf'],
            'D' => ['B', 'C'],
        ]);
        $this->graph->addNode('Expensive', [], self::EXPENSIVE_MICROSECONDS);

        $this->runner = new GeneratedProjectRunner($this->graph);
    }

    protected function tearDown(): void
    {
        $this->runner?->destroy();
    }

    public function testFullRunExecutesEveryNode(): void
    {
        $result = $this->runner->run();

        $this->assertSame(0, $result->exitCode, $result->stdout . $result->stderr);
        $this->assertSame([], $result->skipped());
        $this->assertCount(5, $result->passed(), 'Leaf, B, C, D, Expensive');
        $this->assertSame(1, $this->graph->setupRunCount('Expensive'));
    }

    public function testImmediateRerunSkipsEverythingAndIsMeaningfullyFaster(): void
    {
        $start = hrtime(true);
        $cold = $this->runner->run();
        $fullSeconds = (hrtime(true) - $start) / 1_000_000_000;

        $this->assertSame(0, $cold->exitCode, $cold->stdout . $cold->stderr);
        $this->assertCount(5, $cold->passed());

        $start = hrtime(true);
        $rerun = $this->runner->run();
        $skippedSeconds = (hrtime(true) - $start) / 1_000_000_000;

        $this->assertSame(0, $rerun->exitCode, $rerun->stdout . $rerun->stderr);
        $this->assertSame([], $rerun->passed());
        $this->assertCount(5, $rerun->skipped());

        // A real, honest measured number — not asserted against a hardcoded expectation.
        // The expensive node's ~0.3s of real usleep'd work should dominate the difference.
        $this->assertLessThan($fullSeconds, $skippedSeconds, 'a full-skip run must be measurably faster than the cold run');
        $this->assertGreaterThan(
            self::EXPENSIVE_MICROSECONDS / 1_000_000 * 0.5,
            $fullSeconds - $skippedSeconds,
            'the savings must be substantial enough to be explained by the expensive node alone, not process-startup noise',
        );
    }

    public function testMutatingTheSharedLeafRerunsExactlyItsTransitiveDependentsAndSkipsTheExpensiveNode(): void
    {
        $this->runner->run(); // cold
        $this->runner->run(); // stable: prove full-skip before touching anything

        $this->assertSame(1, $this->graph->setupRunCount('Expensive'), 'sanity: expensive setup ran exactly once so far');

        // Computed from the known edge list, not hand-verified: Leaf's own test plus every
        // node whose run() transitively executes Leaf's code.
        $expectedToRerun = array_merge(['Leaf'], $this->graph->transitiveDependentsOf('Leaf'));
        sort($expectedToRerun);
        $this->assertSame(['B', 'C', 'D', 'Leaf'], $expectedToRerun);

        $this->graph->mutate('Leaf');
        $result = $this->runner->run();

        $this->assertSame(0, $result->exitCode, $result->stdout . $result->stderr);

        $reranNodes = $this->idsToNodeNames($result->passed());
        sort($reranNodes);
        $this->assertSame($expectedToRerun, $reranNodes);

        $skippedNodes = $this->idsToNodeNames($result->skipped());
        $this->assertSame(['Expensive'], $skippedNodes, 'the node that does not depend on the mutated leaf must stay skipped');

        $this->assertSame(
            1,
            $this->graph->setupRunCount('Expensive'),
            'Expensive stayed skipped, so its real expensive setup must NOT have run again',
        );
    }

    public function testRevertingTheMutationReinvalidatesThenStabilizesBackToFullSkip(): void
    {
        $this->runner->run(); // cold

        $expectedDependents = array_merge(['Leaf'], $this->graph->transitiveDependentsOf('Leaf'));
        sort($expectedDependents);

        $this->graph->mutate('Leaf');
        $mutatedRun = $this->runner->run();
        $this->assertSame($expectedDependents, self::sorted($this->idsToNodeNames($mutatedRun->passed())));

        $this->graph->revert('Leaf');
        $revertRun = $this->runner->run();

        $this->assertSame(0, $revertRun->exitCode, $revertRun->stdout . $revertRun->stderr);
        $this->assertSame(
            $expectedDependents,
            self::sorted($this->idsToNodeNames($revertRun->passed())),
            'the stored hash still reflects the mutated content, so reverting must rerun the same dependents again',
        );

        $stableRun = $this->runner->run();

        $this->assertSame(0, $stableRun->exitCode, $stableRun->stdout . $stableRun->stderr);
        $this->assertSame([], $stableRun->passed(), 'back to the exact original content, the suite must fully stabilize back to skip');
        $this->assertCount(5, $stableRun->skipped());
        $this->assertSame(1, $this->graph->setupRunCount('Expensive'), 'Expensive never depended on Leaf and must never have rerun');
    }

    /**
     * @param list<string> $ids "FQCN::method"
     *
     * @return list<string> bare node names ("B", "Expensive", ...)
     */
    private function idsToNodeNames(array $ids): array
    {
        $names = [];

        foreach ($this->graph->nodeNames() as $name) {
            if (in_array($this->graph->testFqcn($name) . '::test' . $name . 'Runs', $ids, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
