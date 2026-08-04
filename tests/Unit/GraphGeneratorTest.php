<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Tests\Support\GraphGenerator;
use Vusys\Zefram\Tests\Support\Runnable;

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

    public function test_chain_topology_writes_real_executable_classes(): void
    {
        $graph = $this->generate(['A' => [], 'B' => ['A'], 'C' => ['B']]);

        $this->assertFileExists($graph->sourcePath('A'));
        $this->assertFileExists($graph->sourcePath('B'));
        $this->assertFileExists($graph->sourcePath('C'));
        $this->assertFileExists($graph->testPath('A'));
        $this->assertFileExists($graph->bootstrapPath());

        require $graph->bootstrapPath();

        $c = $graph->classFqcn('C');
        $instance = new $c;
        assert($instance instanceof Runnable);
        $instance->run(); // exercises the whole A<-B<-C chain; must not throw

        $this->addToAssertionCount(1);
    }

    public function test_diamond_topology_dependents_are_computable(): void
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
        $instance = new $d;
        assert($instance instanceof Runnable);
        $instance->run();

        $this->addToAssertionCount(1);
    }

    public function test_shared_leaf_fan_out_dependents(): void
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

    public function test_unknown_dependency_is_rejected(): void
    {
        $graph = new GraphGenerator;
        $this->generators[] = $graph;

        $this->expectException(\RuntimeException::class);
        $graph->addNode('B', ['A']);
    }

    public function test_expensive_node_does_real_measurable_work_and_writes_setup_log(): void
    {
        $graph = GraphGenerator::fromEdges(['Leaf' => []]);
        $this->generators[] = $graph;
        $graph->addNode('Expensive', ['Leaf'], expensiveMicroseconds: 50_000);
        $graph->write();

        require $graph->bootstrapPath();

        $this->assertSame(0, $graph->setupRunCount('Expensive'));

        $class = $graph->classFqcn('Expensive');
        $instance = new $class;
        assert($instance instanceof Runnable);

        $start = hrtime(true);
        $instance->run();
        $elapsed = (hrtime(true) - $start) / 1_000_000_000;

        $this->assertGreaterThanOrEqual(0.045, $elapsed, 'expensive setup must do real, measurable work, not a faked timing number');
        $this->assertSame(1, $graph->setupRunCount('Expensive'));

        $again = new $class;
        assert($again instanceof Runnable);
        $again->run();
        $this->assertSame(2, $graph->setupRunCount('Expensive'), 'the log accumulates one line per real execution');
    }

    public function test_mutate_changes_source_file_and_revert_restores_it(): void
    {
        $graph = $this->generate(['A' => []]);

        $before = (string) file_get_contents($graph->sourcePath('A'));

        $graph->mutate('A');
        $mutated = (string) file_get_contents($graph->sourcePath('A'));
        $this->assertNotSame($before, $mutated);
        $this->assertStringContainsString('$mutation = true;', $mutated);

        $graph->revert('A');
        $reverted = (string) file_get_contents($graph->sourcePath('A'));
        $this->assertSame($before, $reverted);
    }

    public function test_two_generators_in_the_same_process_do_not_collide(): void
    {
        $first = $this->generate(['A' => []]);
        $second = $this->generate(['A' => []]);

        $this->assertNotSame($first->namespace, $second->namespace);
        $this->assertNotSame($first->classFqcn('A'), $second->classFqcn('A'));

        require $first->bootstrapPath();
        require $second->bootstrapPath();

        $a1 = $first->classFqcn('A');
        $a2 = $second->classFqcn('A');
        new $a1;
        new $a2;

        $this->addToAssertionCount(1);
    }

    /**
     * @param  array<string, list<string>>  $edges
     */
    private function generate(array $edges): GraphGenerator
    {
        $graph = GraphGenerator::fromEdges($edges)->write();
        $this->generators[] = $graph;

        return $graph;
    }
}
