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

        foreach (glob($this->cacheRoot().'/.zefram/*/tests/*.json') ?: [] as $shardFile) {
            $decoded = json_decode((string) file_get_contents($shardFile), true);
            if (! is_array($decoded)) {
                continue;
            }
            if (! isset($decoded['class'], $decoded['tests'])) {
                continue;
            }
            if (! is_array($decoded['tests'])) {
                continue;
            }

            foreach ($decoded['tests'] as $key => $record) {
                $shape = self::shardRecordShape($record);

                if ($shape === null) {
                    continue; // corrupt individual record: not this trait's job to repair it
                }

                $records[$decoded['class'].'::'.$key] = $shape;
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
            if (array_key_exists($relativePath, $record['files'])) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * Mirrors Vusys\Zefram\Store\Store's own defensive decode — the shard file on disk is
     * untrusted input as far as static types are concerned, even though in practice it's
     * always written by the package itself.
     *
     * @return array{files: array<string, string>, duration: float, result: string}|null
     */
    private static function shardRecordShape(mixed $record): ?array
    {
        if (! is_array($record) || ! is_array($record['files'] ?? null) || ! is_string($record['result'] ?? null)) {
            return null;
        }

        if (! is_int($record['duration'] ?? null) && ! is_float($record['duration'] ?? null)) {
            return null;
        }

        $files = [];

        foreach ($record['files'] as $path => $hash) {
            if (! is_string($path) || ! is_string($hash)) {
                return null;
            }

            $files[$path] = $hash;
        }

        return [
            'files' => $files,
            'duration' => (float) $record['duration'],
            'result' => $record['result'],
        ];
    }
}
