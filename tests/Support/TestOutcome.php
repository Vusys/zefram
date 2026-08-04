<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

/**
 * One <testcase/> from a child process's JUnit log.
 */
final readonly class TestOutcome
{
    public const PASSED = 'passed';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const ERRORED = 'errored';

    public function __construct(
        public string $class,
        public string $method,
        public string $status,
    ) {}

    public function id(): string
    {
        return $this->class.'::'.$this->method;
    }
}
