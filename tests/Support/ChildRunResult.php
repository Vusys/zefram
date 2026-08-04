<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

/**
 * The result of one child PHPUnit process run: exit code, captured output, and structured
 * per-test outcomes parsed from its JUnit log.
 */
final readonly class ChildRunResult
{
    /**
     * @param  list<TestOutcome>  $outcomes
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public array $outcomes,
    ) {}

    public function statusOf(string $id): ?string
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->id() === $id) {
                return $outcome->status;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function idsWithStatus(string $status): array
    {
        $ids = array_values(array_map(
            static fn (TestOutcome $o): string => $o->id(),
            array_filter($this->outcomes, static fn (TestOutcome $o): bool => $o->status === $status),
        ));

        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    public function passed(): array
    {
        return $this->idsWithStatus(TestOutcome::PASSED);
    }

    /**
     * @return list<string>
     */
    public function skipped(): array
    {
        return $this->idsWithStatus(TestOutcome::SKIPPED);
    }
}
