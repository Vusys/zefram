<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Tests\Support\GraphGenerator;

final class GraphGeneratorTest extends TestCase
{
    /** @var list<GraphGenerator> */
    private array $generators = [];

    protected function tearDown(): void
    {
        foreach ($this->generators as $generator) {
            $generator->destroy();
        }

        $this->generators = [];
    }

    public function testChainTopologyWritesRealExecutableClasses(): void
    {
        $graph = $this->generate(['A' => [], 'B' => ['A'], 'C' => ['B']]);

        $this->assertFileExists($graph->sourcePath('A'));
        $this->assertFileExists($graph->sourcePath('B'));
        $this->assertFileExists($graph->sourcePath('C'));
        $this->assertFileExists($graph->testPath('A'));
        $this->assertFileExists($graph->bootstrapPath());

        require $graph->bootstrapPath();

        $c = $graph->classFqcn('C');
        $instance = new $c();
        $instance->run(); // exercises the whole A<-B<-C chain; must not throw

        $this->assertTrue(true);
    }

    public function testDiamondTopologyDependentsAreComputable(): void
    {
        $graph = $this->generate([
            'A' => [],
            'B' => ['A'],
            'C' => ['A'],
            'D' => ['B', 'C'],
        ]);

        $this->assertSame(['B', 'C'], $graph->directDependentsOf('A'));
        $this->assertSame(['D'], $graph->directDependentsOf('B'));

        require $graph->bootstrapPath();

        $d = $graph->classFqcn('D');
        (new $d())->run();

        $this->assertTrue(true);
    }

    public function testSharedLeafFanOutDependents(): void
    {
        $graph = $this->generate([
            'Leaf' => [],
            'X' => ['Leaf'],
            'Y' => ['Leaf'],
            'Z' => ['Leaf'],
        ]);

        $this->assertSame(['X', 'Y', 'Z'], $graph->directDependentsOf('Leaf'));
        $this->assertSame([], $graph->directDependentsOf('X'));
    }

    public function testUnknownDependencyIsRejected(): void
    {
        $graph = new GraphGenerator();
        $this->generators[] = $graph;

        $this->expectException(\RuntimeException::class);
        $graph->addNode('B', ['A']);
    }

    public function testExpensiveNodeDoesRealMeasurableWorkAndWritesSetupLog(): void
    {
        $graph = GraphGenerator::fromEdges(['Leaf' => []]);
        $this->generators[] = $graph;
        $graph->addNode('Expensive', ['Leaf'], expensiveMicroseconds: 50_000);
        $graph->write();

        require $graph->bootstrapPath();

        $this->assertSame(0, $graph->setupRunCount('Expensive'));

        $class = $graph->classFqcn('Expensive');
        $start = hrtime(true);
        (new $class())->run();
        $elapsed = (hrtime(true) - $start) / 1_000_000_000;

        $this->assertGreaterThanOrEqual(0.045, $elapsed, 'expensive setup must do real, measurable work, not a faked timing number');
        $this->assertSame(1, $graph->setupRunCount('Expensive'));

        (new $class())->run();
        $this->assertSame(2, $graph->setupRunCount('Expensive'), 'the log accumulates one line per real execution');
    }

    public function testMutateChangesSourceFileAndRevertRestoresIt(): void
    {
        $graph = $this->generate(['A' => []]);

        $before = file_get_contents($graph->sourcePath('A'));

        $graph->mutate('A');
        $mutated = file_get_contents($graph->sourcePath('A'));
        $this->assertNotSame($before, $mutated);
        $this->assertStringContainsString('$mutation = true;', $mutated);

        $graph->revert('A');
        $reverted = file_get_contents($graph->sourcePath('A'));
        $this->assertSame($before, $reverted);
    }

    public function testTwoGeneratorsInTheSameProcessDoNotCollide(): void
    {
        $first = $this->generate(['A' => []]);
        $second = $this->generate(['A' => []]);

        $this->assertNotSame($first->namespace, $second->namespace);
        $this->assertNotSame($first->classFqcn('A'), $second->classFqcn('A'));

        require $first->bootstrapPath();
        require $second->bootstrapPath();

        $a1 = $first->classFqcn('A');
        $a2 = $second->classFqcn('A');
        new $a1();
        new $a2();

        $this->assertTrue(true);
    }

    /**
     * @param array<string, list<string>> $edges
     */
    private function generate(array $edges): GraphGenerator
    {
        $graph = GraphGenerator::fromEdges($edges)->write();
        $this->generators[] = $graph;

        return $graph;
    }
}
