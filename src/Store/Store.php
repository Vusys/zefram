<?php

declare(strict_types=1);

namespace Vusys\Zefram\Store;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * One JSON shard per test class, loaded lazily, written atomically. Shards are
 * self-contained so parallel runners distributing work per class (paratest's
 * WrapperRunner) never have two processes writing the same file.
 */
final class Store
{
    /** @var array<string, array<string, TestRecord>> */
    private array $shards = [];

    /** @var array<string, true> */
    private array $loaded = [];

    /** @var array<string, true> */
    private array $dirty = [];

    public function __construct(private readonly string $directory) {}

    public function get(string $class, string $testKey): ?TestRecord
    {
        $this->load($class);

        return $this->shards[$class][$testKey] ?? null;
    }

    public function put(string $class, string $testKey, TestRecord $record): void
    {
        $this->load($class);

        $this->shards[$class][$testKey] = $record;
        $this->dirty[$class] = true;
    }

    public function drop(string $class, string $testKey): void
    {
        $this->load($class);

        if (! isset($this->shards[$class][$testKey])) {
            return;
        }

        unset($this->shards[$class][$testKey]);
        $this->dirty[$class] = true;
    }

    public function flush(): void
    {
        foreach (array_keys($this->dirty) as $class) {
            $this->writeShard($class);
        }

        $this->dirty = [];
    }

    /**
     * Drops every stored record whose id ("FullyQualified\Class::testKey") is not in
     * $observedIds — a test method deleted from source (renamed, removed, or its whole
     * class deleted) leaves an orphaned record forever otherwise (harmless for correctness,
     * but drifts shards and `zefram stats` (#9) from reality). Must run before flush() so
     * the drops persist. Scans every shard file on disk under this store's own directory,
     * not just classes already touched this run — an entirely-deleted class's shard is
     * never loaded by get()/put() at all otherwise, since no test of it ran or was skipped.
     * Caller must only invoke this on a full run (see Engine::isFullRun()): a partial or
     * parallel run only observes a subset of the suite and would wrongly prune the rest.
     *
     * @param  array<string, true>  $observedIds  every test id actually seen (ran or skipped) this pass
     */
    public function pruneStaleRecords(array $observedIds): void
    {
        foreach (glob($this->directory.'/tests/*.json') ?: [] as $shardFile) {
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

            $class = (string) $decoded['class'];
            $this->load($class);

            foreach (array_keys($this->shards[$class] ?? []) as $testKey) {
                if (! isset($observedIds[$class.'::'.$testKey])) {
                    $this->drop($class, $testKey);
                }
            }
        }
    }

    private function load(string $class): void
    {
        if (isset($this->loaded[$class])) {
            return;
        }

        $this->loaded[$class] = true;
        $this->shards[$class] = [];

        $file = $this->shardFile($class);

        if (! is_file($file)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (! is_array($decoded) || ($decoded['class'] ?? null) !== $class || ! is_array($decoded['tests'] ?? null)) {
            return; // corrupt or hash-collided shard: treat as empty, it will be rebuilt
        }

        foreach ($decoded['tests'] as $testKey => $record) {
            $shape = $this->recordShape($record);

            if ($shape === null) {
                continue; // corrupt individual record: drop it, it will be rebuilt
            }

            $this->shards[$class][(string) $testKey] = TestRecord::fromArray($shape);
        }
    }

    /**
     * Validates a single json_decode()d record against TestRecord::fromArray()'s expected
     * shape, since the file on disk is untrusted input (hand-edited, truncated, hash-collided
     * class name, or from a future/older shard format).
     *
     * @return array{files: array<string, string>, duration: float, result: string}|null
     */
    private function recordShape(mixed $record): ?array
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

    private function writeShard(string $class): void
    {
        $records = $this->shards[$class] ?? [];
        $file = $this->shardFile($class);

        if ($records === []) {
            if (is_file($file)) {
                @unlink($file);
            }

            return;
        }

        $directory = dirname($file);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Zefram could not create cache directory: {$directory}");
        }

        $payload = json_encode([
            'class' => $class,
            'tests' => array_map(static fn (TestRecord $r): array => $r->toArray(), $records),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $temp = $file.'.'.getmypid().'.tmp';

        if (@file_put_contents($temp, $payload) === false || ! @rename($temp, $file)) {
            @unlink($temp);

            throw new RuntimeException("Zefram could not write shard: {$file}");
        }
    }

    private function shardFile(string $class): string
    {
        return $this->directory.'/tests/'.hash('xxh128', $class).'.json';
    }

    /**
     * Deletes every fingerprint directory under $cacheRoot except $currentFingerprint's.
     * Watched-path changes are folded into the fingerprint by design (see #20): any edit to
     * a watched file spins up a brand-new fingerprint directory, and nothing else deletes
     * the old one — left unaddressed, .zefram/ grows unboundedly over a normal development
     * session. Caller must only invoke this on a full run (see Engine::isFullRun()) — a
     * partial or parallel run must never delete a fingerprint directory another process
     * might still be relying on.
     */
    public static function pruneSiblings(string $cacheRoot, string $currentFingerprint): void
    {
        foreach (glob(rtrim($cacheRoot, '/').'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== $currentFingerprint) {
                self::removeDirectory($dir);
            }
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            assert($item instanceof SplFileInfo);
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
