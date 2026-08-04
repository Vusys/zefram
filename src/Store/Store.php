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
    /** @var array<class-string, array<string, TestRecord>> */
    private array $shards = [];

    /** @var array<class-string, true> */
    private array $loaded = [];

    /** @var array<class-string, true> */
    private array $dirty = [];

    public function __construct(private readonly string $directory)
    {
    }

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

        if (!isset($this->shards[$class][$testKey])) {
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

    private function load(string $class): void
    {
        if (isset($this->loaded[$class])) {
            return;
        }

        $this->loaded[$class] = true;
        $this->shards[$class] = [];

        $file = $this->shardFile($class);

        if (!is_file($file)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded) || ($decoded['class'] ?? null) !== $class || !is_array($decoded['tests'] ?? null)) {
            return; // corrupt or hash-collided shard: treat as empty, it will be rebuilt
        }

        foreach ($decoded['tests'] as $testKey => $record) {
            $this->shards[$class][(string) $testKey] = TestRecord::fromArray($record);
        }
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

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Zefram could not create cache directory: {$directory}");
        }

        $payload = json_encode([
            'class' => $class,
            'tests' => array_map(static fn (TestRecord $r): array => $r->toArray(), $records),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $temp = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($temp, $payload) === false || !@rename($temp, $file)) {
            @unlink($temp);

            throw new RuntimeException("Zefram could not write shard: {$file}");
        }
    }

    private function shardFile(string $class): string
    {
        return $this->directory . '/tests/' . hash('xxh128', $class) . '.json';
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
        foreach (glob(rtrim($cacheRoot, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== $currentFingerprint) {
                self::removeDirectory($dir);
            }
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
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
