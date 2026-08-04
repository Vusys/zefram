<?php

declare(strict_types=1);

namespace Vusys\Zefram\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use Vusys\Zefram\Attribute\NeverSkip;

/**
 * Flags test methods calling known non-deterministic/runtime-only things — time, randomness,
 * live network, raw env reads — without a #[NeverSkip] attribute: a linter for "this test is
 * invisible to zefram's coverage heuristic and you probably don't know it" (see #20's "Known
 * blind spots" and #53).
 *
 * Framework-agnostic checks always run. The Laravel-aware checks (now(), Carbon::now(),
 * Http::, Auth::user(), env()) are plain name/string matching, not real type resolution —
 * Larastan would let a more sophisticated version resolve facade aliases precisely, but
 * that's a real engineering step up from this rule's scope (see the class docblock in the
 * issue for the full rationale); string matching is a deliberate, documented simplification,
 * not an oversight, and behaves identically whether or not Larastan is present in the
 * consuming project.
 *
 * Ordering-sensitive, not full dataflow: a `#[NeverSkip]`-style neutralizer
 * ($this->travel()/freezeTime()/Carbon::setTestNow(), Http::fake()) only suppresses calls
 * that come *after* it in the method's call list (as found by a single top-to-bottom
 * NodeFinder pass) — good enough for a lint, not meant to be sound. Doesn't know about
 * watched-path config (that's project-level phpunit.xml state a per-node AST rule has no
 * access to) — #[NeverSkip] is the only suppression this rule understands.
 *
 * @implements Rule<ClassMethod>
 */
final class NeverSkipCandidateRule implements Rule
{
    /** @var array<string, true> */
    private const RANDOM_FUNCTIONS = [
        'rand' => true,
        'mt_rand' => true,
        'random_int' => true,
        'uniqid' => true,
    ];

    /** @var array<string, true> */
    private const TIME_FUNCTIONS = [
        'time' => true,
        'microtime' => true,
        'now' => true, // Laravel helper
    ];

    /** @var array<string, true> */
    private const NETWORK_FUNCTIONS = [
        'curl_exec' => true,
        'fsockopen' => true,
    ];

    /** @var array<string, true> */
    private const ENV_FUNCTIONS = [
        'getenv' => true,
        'env' => true, // Laravel helper
    ];

    /** @var array<string, true> */
    private const TIME_NEUTRALIZER_METHODS = [
        'travel' => true,
        'travelto' => true,
        'travelback' => true,
        'freezetime' => true,
        'freezesecond' => true,
    ];

    /** @var array<string, true> */
    private const TIME_CLASSES = [
        'carbon' => true,
        'carbonimmutable' => true,
    ];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @param  ClassMethod  $node
     * @return list<RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->isTestMethod($node)) {
            return [];
        }

        if ($this->hasNeverSkip($node->getAttrGroups()) || $this->classHasNeverSkip($scope)) {
            return [];
        }

        $stmts = $node->getStmts();

        if ($stmts === null) {
            return [];
        }

        $calls = (new NodeFinder)->find(
            $stmts,
            static fn (Node $n): bool => $n instanceof FuncCall || $n instanceof StaticCall || $n instanceof MethodCall,
        );

        $errors = [];
        $timeNeutralized = false;
        $networkFaked = false;

        foreach ($calls as $call) {
            $classified = $this->classify($call);

            if ($classified === null) {
                continue;
            }

            [$kind, $label] = $classified;

            if ($kind === 'time-neutralizer') {
                $timeNeutralized = true;
            } elseif ($kind === 'network-neutralizer') {
                $networkFaked = true;
            } elseif ($kind === 'time' && ! $timeNeutralized) {
                $errors[] = $this->buildError($call, $label);
            } elseif ($kind === 'network' && ! $networkFaked) {
                $errors[] = $this->buildError($call, $label);
            } elseif ($kind === 'always') {
                $errors[] = $this->buildError($call, $label);
            }
        }

        return $errors;
    }

    /**
     * @return array{0: 'time'|'time-neutralizer'|'network'|'network-neutralizer'|'always', 1: string}|null
     */
    private function classify(Node $call): ?array
    {
        if ($call instanceof FuncCall) {
            return $this->classifyFuncCall($call);
        }

        if ($call instanceof StaticCall) {
            return $this->classifyStaticCall($call);
        }

        if ($call instanceof MethodCall) {
            return $this->classifyMethodCall($call);
        }

        return null;
    }

    /**
     * @return array{0: 'time'|'network'|'always', 1: string}|null
     */
    private function classifyFuncCall(FuncCall $call): ?array
    {
        $name = $this->funcCallName($call);

        if ($name === null) {
            return null;
        }

        if (isset(self::RANDOM_FUNCTIONS[$name])) {
            return ['always', "{$name}() is non-deterministic and invisible to zefram's coverage heuristic"];
        }

        if (isset(self::TIME_FUNCTIONS[$name])) {
            return ['time', "{$name}() is non-deterministic and invisible to zefram's coverage heuristic"];
        }

        if (isset(self::NETWORK_FUNCTIONS[$name])) {
            return ['network', "{$name}() makes a live network call invisible to zefram's coverage heuristic"];
        }

        if (isset(self::ENV_FUNCTIONS[$name])) {
            return ['always', "{$name}() reads runtime environment state invisible to zefram's coverage heuristic"];
        }

        return null;
    }

    /**
     * @return array{0: 'time'|'time-neutralizer'|'network'|'network-neutralizer'|'always', 1: string}|null
     */
    private function classifyStaticCall(StaticCall $call): ?array
    {
        $class = $this->staticCallClassBaseName($call);
        $method = $this->staticCallMethodName($call);

        if ($class === null || $method === null) {
            return null;
        }

        $classLower = strtolower($class);
        $methodLower = strtolower($method);

        if (isset(self::TIME_CLASSES[$classLower])) {
            if ($methodLower === 'now') {
                return ['time', "{$class}::{$method}() is non-deterministic and invisible to zefram's coverage heuristic"];
            }

            if ($methodLower === 'settestnow') {
                return ['time-neutralizer', ''];
            }
        }

        if ($classLower === 'http') {
            if ($methodLower === 'fake') {
                return ['network-neutralizer', ''];
            }

            return ['network', "Http::{$method}() makes a live network call invisible to zefram's coverage heuristic unless faked"];
        }

        if ($classLower === 'auth' && $methodLower === 'user') {
            return ['always', 'Auth::user() reads session-derived state invisible to zefram\'s coverage heuristic'];
        }

        return null;
    }

    /**
     * @return array{0: 'time-neutralizer', 1: ''}|null
     */
    private function classifyMethodCall(MethodCall $call): ?array
    {
        if (! $call->var instanceof Variable || $call->var->name !== 'this') {
            return null;
        }

        $method = $call->name instanceof Node\Identifier ? $call->name->toString() : null;

        if ($method === null) {
            return null;
        }

        if (isset(self::TIME_NEUTRALIZER_METHODS[strtolower($method)])) {
            return ['time-neutralizer', ''];
        }

        return null;
    }

    private function funcCallName(FuncCall $call): ?string
    {
        return $call->name instanceof Node\Name ? $call->name->toString() : null;
    }

    private function staticCallClassBaseName(StaticCall $call): ?string
    {
        if (! $call->class instanceof Node\Name) {
            return null;
        }

        $parts = explode('\\', $call->class->toString());

        return end($parts) ?: null;
    }

    private function staticCallMethodName(StaticCall $call): ?string
    {
        return $call->name instanceof Node\Identifier ? $call->name->toString() : null;
    }

    private function isTestMethod(ClassMethod $node): bool
    {
        if (! $node->isPublic()) {
            return false;
        }

        if (str_starts_with($node->name->toString(), 'test')) {
            return true;
        }

        foreach ($node->getAttrGroups() as $group) {
            foreach ($group->attrs as $attr) {
                $parts = explode('\\', $attr->name->toString());

                if (end($parts) === 'Test') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<Node\AttributeGroup>  $attrGroups
     */
    private function hasNeverSkip(array $attrGroups): bool
    {
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $parts = explode('\\', $attr->name->toString());

                if (end($parts) === 'NeverSkip') {
                    return true;
                }
            }
        }

        return false;
    }

    private function classHasNeverSkip(Scope $scope): bool
    {
        $class = $scope->getClassReflection();

        if (! $class instanceof ClassReflection) {
            return false;
        }

        return $class->getNativeReflection()->getAttributes(NeverSkip::class) !== [];
    }

    private function buildError(Node $node, string $message): RuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('zefram.neverSkipCandidate')
            ->line($node->getStartLine())
            ->build();
    }
}
