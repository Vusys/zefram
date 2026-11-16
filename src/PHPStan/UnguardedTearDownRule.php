<?php

declare(strict_types=1);

namespace Vusys\Zefram\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Vusys\Zefram\ZeframAware;

/**
 * Flags a tearDown() on a ZeframAware test that uses the framework before parent::tearDown()
 * without first checking zeframSkipped() (#87). Zefram skips from setUp() before the app
 * boots, but PHPUnit still runs tearDown(), so a facade call there throws and the skipped
 * test is reported as an error.
 *
 * "Uses the framework" is name matching, like NeverSkipCandidateRule: a static call on a
 * class (a facade), $this->app, or app()/resolve()/config(). A zeframSkipped() call or an
 * isset($this->app) anywhere in the method counts as the guard.
 *
 * @implements Rule<ClassMethod>
 */
final class UnguardedTearDownRule implements Rule
{
    /** @var array<string, true> */
    private const FRAMEWORK_FUNCTIONS = [
        'app' => true,
        'resolve' => true,
        'config' => true,
    ];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $scope->getClassReflection();

        if (strtolower($node->name->toString()) !== 'teardown' || ! $class instanceof ClassReflection || ! $this->usesZefram($class) || $node->stmts === null) {
            return [];
        }

        $finder = new NodeFinder;

        if ($finder->findFirst($node->stmts, $this->isGuard(...)) instanceof Node) {
            return [];
        }

        foreach ($node->stmts as $stmt) {
            if ($finder->findFirst($stmt, $this->isParentTearDown(...)) instanceof Node) {
                break;
            }

            $use = $finder->findFirst($stmt, $this->usesFramework(...));

            if ($use instanceof Node) {
                return [
                    RuleErrorBuilder::message('tearDown() uses the framework before parent::tearDown() without checking $this->zeframSkipped(). A test zefram skipped never booted the app, so this throws.')
                        ->identifier('zefram.unguardedTearDown')
                        ->line($use->getStartLine())
                        ->tip('Return early with parent::tearDown() when $this->zeframSkipped() is true.')
                        ->build(),
                ];
            }
        }

        return [];
    }

    private function usesZefram(ClassReflection $class): bool
    {
        foreach ([$class, ...$class->getParents()] as $candidate) {
            if (array_key_exists(ZeframAware::class, $candidate->getTraits(true))) {
                return true;
            }
        }

        return false;
    }

    private function isGuard(Node $node): bool
    {
        if ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toLowerString() === 'zeframskipped') {
            return true;
        }

        if (! $node instanceof Isset_) {
            return false;
        }

        foreach ($node->vars as $var) {
            if ($this->isThisApp($var)) {
                return true;
            }
        }

        return false;
    }

    private function isParentTearDown(Node $node): bool
    {
        return $node instanceof StaticCall
            && $node->class instanceof Name && $node->class->toLowerString() === 'parent'
            && $node->name instanceof Identifier && $node->name->toLowerString() === 'teardown';
    }

    private function usesFramework(Node $node): bool
    {
        if ($node instanceof StaticCall) {
            return $node->class instanceof Name && ! in_array($node->class->toLowerString(), ['parent', 'self', 'static'], true);
        }

        if ($node instanceof FuncCall) {
            return $node->name instanceof Name && isset(self::FRAMEWORK_FUNCTIONS[$node->name->toLowerString()]);
        }

        return $this->isThisApp($node);
    }

    private function isThisApp(Node $node): bool
    {
        return $node instanceof PropertyFetch
            && $node->var instanceof Variable && $node->var->name === 'this'
            && $node->name instanceof Identifier && $node->name->toString() === 'app';
    }
}
