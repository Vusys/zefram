<?php

declare(strict_types=1);

namespace Vusys\Zefram\Store;

final readonly class TestRecord
{
    public const RESULT_PASSED = 'passed';

    /**
     * Each record pins its own path => hash map. A shard-level shared hash table would be
     * unsafe: a --filter run rerunning one test against a changed file would silently
     * revalidate sibling records that never ran against the new code.
     *
     * @param  array<string, string>  $files  relative path => xxh128 of content when this test last passed
     */
    public function __construct(
        public array $files,
        public float $duration,
        public string $result,
    ) {}

    /**
     * @param  array{files: array<string, string>, duration: float, result: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['files'], $data['duration'], $data['result']);
    }

    /**
     * @return array{files: array<string, string>, duration: float, result: string}
     */
    public function toArray(): array
    {
        return [
            'files' => $this->files,
            'duration' => $this->duration,
            'result' => $this->result,
        ];
    }
}
