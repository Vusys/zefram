<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Generates a small, real PHP class graph plus matching PHPUnit test classes, parameterized
 * by a declarative edge list. Genuine test-support code, not a fabricated app: nodes are
 * generic ("A", "B", ...), but the emitted files are real, executable PHP that pcov captures
 * like any other project code.
 *
 * Each node's run() calls run() on every direct dependency, so a node's test naturally
 * exercises (and pcov-covers) its whole transitive dependency chain — an empty method body
 * would compile to zero executable statements and never show up in coverage, so every
 * generated run() always contains at least one real statement.
 *
 * Output lives under a unique temp directory with a unique PHP namespace per instance, so
 * multiple generators can coexist in the same process (or be require()'d by a child PHPUnit
 * process) without class-redeclaration collisions.
 */
final class GraphGenerator
{
    public readonly string $root;

    public readonly string $namespace;

    /** @var array<string, Node> */
    private array $nodes = [];

    public function __construct(?string $namespace = null)
    {
        $this->namespace = $namespace ?? 'Zefram\\Generated\\G' . bin2hex(random_bytes(6));
        $this->root = sys_get_temp_dir() . '/zefram-graph-' . bin2hex(random_bytes(8));

        mkdir($this->root . '/src', 0775, true);
        mkdir($this->root . '/tests', 0775, true);
    }

    /**
     * Build a generator from a declarative edge list, e.g. ['A' => [], 'B' => ['A'], 'C' => ['A', 'B']]
     * meaning C depends on A and B. Order does not matter; dependencies may be declared
     * after their dependents.
     *
     * @param array<string, list<string>> $edges
     */
    public static function fromEdges(array $edges, ?string $namespace = null): self
    {
        $generator = new self($namespace);

        foreach ($edges as $name => $dependencies) {
            $generator->addNode((string) $name, $dependencies);
        }

        return $generator;
    }

    /**
     * @param list<string> $dependencies must already have been added
     */
    public function addNode(string $name, array $dependencies = [], ?int $expensiveMicroseconds = null): self
    {
        foreach ($dependencies as $dependency) {
            if (!isset($this->nodes[$dependency])) {
                throw new RuntimeException("Zefram GraphGenerator: node '{$name}' depends on unknown node '{$dependency}'; add it first.");
            }
        }

        $this->nodes[$name] = new Node($name, $dependencies, $expensiveMicroseconds);

        return $this;
    }

    /**
     * Write every node's source class, matching test class, and the shared bootstrap
     * (autoloader) to disk. Idempotent — safe to call again after mutating node state.
     */
    public function write(): self
    {
        foreach ($this->nodes as $node) {
            file_put_contents($this->sourcePath($node->name), $this->renderSource($node));
            file_put_contents($this->testPath($node->name), $this->renderTest($node));
        }

        file_put_contents($this->bootstrapPath(), $this->renderBootstrap());

        return $this;
    }

    public function classFqcn(string $name): string
    {
        return $this->namespace . '\\' . $name;
    }

    public function testFqcn(string $name): string
    {
        return $this->namespace . '\\Tests\\' . $name . 'Test';
    }

    public function sourcePath(string $name): string
    {
        return $this->root . '/src/' . $name . '.php';
    }

    public function testPath(string $name): string
    {
        return $this->root . '/tests/' . $name . 'Test.php';
    }

    public function bootstrapPath(): string
    {
        return $this->root . '/bootstrap.php';
    }

    public function setupLogPath(): string
    {
        return $this->root . '/setup.log';
    }

    /**
     * How many times an expensive node's setup actually ran, per its on-disk marker —
     * the "setup.log" proof device: assert this does NOT grow when the node stays skipped.
     */
    public function setupRunCount(string $name): int
    {
        if (!is_file($this->setupLogPath())) {
            return 0;
        }

        $lines = array_filter(explode("\n", (string) file_get_contents($this->setupLogPath())), static fn (string $l): bool => $l !== '');

        return count(array_filter($lines, static fn (string $l): bool => $l === $name));
    }

    /**
     * @return list<string> names of nodes that directly depend on $name
     */
    public function directDependentsOf(string $name): array
    {
        $dependents = [];

        foreach ($this->nodes as $node) {
            if (in_array($name, $node->dependencies, true)) {
                $dependents[] = $node->name;
            }
        }

        return $dependents;
    }

    /**
     * Every node whose test *actually executes* $name's code, directly or transitively —
     * since each node's run() calls its dependencies' run(), a node's coverage includes
     * every ancestor in its dependency chain, not just its direct dependencies. This is the
     * set a real content-hash cache invalidates when $name changes, computed from the known
     * edge list rather than by hand.
     *
     * @return list<string>
     */
    public function transitiveDependentsOf(string $name): array
    {
        $found = [];
        $frontier = [$name];

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $current) {
                foreach ($this->directDependentsOf($current) as $dependent) {
                    if (!in_array($dependent, $found, true)) {
                        $found[] = $dependent;
                        $next[] = $dependent;
                    }
                }
            }

            $frontier = $next;
        }

        sort($found);

        return $found;
    }

    /**
     * @return list<string> every node name currently defined
     */
    public function nodeNames(): array
    {
        return array_keys($this->nodes);
    }

    public function mutate(string $name, string $extraCode = "\n        \$mutation = true;\n"): void
    {
        $this->nodes[$name] = $this->nodes[$name]->withExtraRunCode($extraCode);
        file_put_contents($this->sourcePath($name), $this->renderSource($this->nodes[$name]));
    }

    public function revert(string $name): void
    {
        $this->nodes[$name] = $this->nodes[$name]->withExtraRunCode('');
        file_put_contents($this->sourcePath($name), $this->renderSource($this->nodes[$name]));
    }

    public function destroy(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->root);
    }

    private function renderSource(Node $node): string
    {
        $fqcn = $this->classFqcn($node->name);
        $lastSlash = strrpos($fqcn, '\\');
        $namespace = substr($fqcn, 0, $lastSlash);

        $constructorLines = [];
        $propertyLines = [];

        foreach ($node->dependencies as $index => $dependency) {
            $depFqcn = $this->classFqcn($dependency);
            $constructorLines[] = "            new \\{$depFqcn}(),";
            $propertyLines[] = " * @var list<\\{$depFqcn}|object>";
        }

        $dependenciesArray = $constructorLines === [] ? '[]' : "[\n" . implode("\n", $constructorLines) . "\n        ]";

        $expensiveBlock = '';

        if ($node->expensiveMicroseconds !== null) {
            $logPath = addslashes($this->setupLogPath());
            $name = addslashes($node->name);
            $micros = $node->expensiveMicroseconds;
            $expensiveBlock = <<<PHP


        // Artificial expensive setup: stands in for a seeder-heavy test. Real, measurable
        // work — not a simulated timing number.
        file_put_contents('{$logPath}', "{$name}\\n", FILE_APPEND | LOCK_EX);
        usleep({$micros});
PHP;
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

final class {$node->name}
{
    /** @var list<object> */
    private array \$dependencies;

    public function __construct()
    {
        \$this->dependencies = {$dependenciesArray};
    }

    public function run(): void
    {
        \$executed = true; // guarantees a real executed statement even for a dependency-free leaf{$expensiveBlock}{$node->extraRunCode}

        foreach (\$this->dependencies as \$dependency) {
            \$dependency->run();
        }
    }
}

PHP;
    }

    private function renderTest(Node $node): string
    {
        $fqcn = $this->classFqcn($node->name);
        $testFqcn = $this->testFqcn($node->name);
        $lastSlash = strrpos($testFqcn, '\\');
        $namespace = substr($testFqcn, 0, $lastSlash);
        $className = substr($testFqcn, $lastSlash + 1);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use PHPUnit\Framework\TestCase;
use \\{$fqcn};

final class {$className} extends TestCase
{
    public function test{$node->name}Runs(): void
    {
        \$node = new {$node->name}();
        \$node->run();

        \$this->assertTrue(true);
    }
}

PHP;
    }

    private function renderBootstrap(): string
    {
        $namespace = addslashes($this->namespace);
        $root = addslashes($this->root);

        return <<<PHP
<?php

declare(strict_types=1);

// Generated by Vusys\Zefram\Tests\Support\GraphGenerator. A minimal PSR-4-ish autoloader for
// the generated graph's own namespace; the outer Composer autoloader (required by whatever
// includes this bootstrap) handles PHPUnit and Zefram itself.
spl_autoload_register(static function (string \$class): void {
    \$namespace = '{$namespace}\\\\';
    \$root = '{$root}';

    if (!str_starts_with(\$class, \$namespace)) {
        return;
    }

    \$relative = substr(\$class, strlen(\$namespace));

    if (str_starts_with(\$relative, 'Tests\\\\')) {
        \$path = \$root . '/tests/' . substr(\$relative, strlen('Tests\\\\')) . '.php';
    } else {
        \$path = \$root . '/src/' . str_replace('\\\\', '/', \$relative) . '.php';
    }

    if (is_file(\$path)) {
        require \$path;
    }
});

PHP;
    }
}
