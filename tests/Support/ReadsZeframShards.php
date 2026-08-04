<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

/**
 * Shared by DogfoodRunner (#2) and GeneratedProjectRunner (#23): read a child project's own
 * .zefram/ shards straight off disk, per the storage format documented in #20. Used to get
 * ground truth for "which tests actually recorded this file" from real coverage output,
 * rather than hardcoding a guess in the E2E tests that consume it.
 */
trait ReadsZeframShards
{
    abstract public function cacheRoot(): string;

    /**
     * @return array<string, array{files: array<string, string>, duration: float, result: string}>
     */
    public function shardRecords(): array
    {
        $records = [];

        foreach (glob($this->cacheRoot() . '/.zefram/*/tests/*.json') ?: [] as $shardFile) {
            $decoded = json_decode((string) file_get_contents($shardFile), true);

            if (!is_array($decoded) || !isset($decoded['class'], $decoded['tests']) || !is_array($decoded['tests'])) {
                continue;
            }

            foreach ($decoded['tests'] as $key => $record) {
                $records[$decoded['class'] . '::' . $key] = $record;
            }
        }

        return $records;
    }

    /**
     * @return list<string>
     */
    public function testsThatExecuted(string $relativePath): array
    {
        $ids = [];

        foreach ($this->shardRecords() as $id => $record) {
            if (array_key_exists($relativePath, $record['files'] ?? [])) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $ids;
    }
}
