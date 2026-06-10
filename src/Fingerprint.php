<?php

declare(strict_types=1);

namespace Vusys\Zefram;

use Vusys\Zefram\Hash\FileHasher;

/**
 * The global cache namespace. Anything that can change test behavior without appearing in
 * per-test coverage folds in here; a mismatch invalidates the entire cache. This is also
 * how vendor code is handled: composer.lock changes, everything reruns — we never track
 * vendor files individually.
 */
final class Fingerprint
{
    public static function compute(Config $config, string $driverName, string $engineVersion, FileHasher $hasher): string
    {
        $root = rtrim($config->projectRoot, '/');

        $parts = [
            'php' => PHP_VERSION,
            'zefram' => $engineVersion,
            'driver' => $driverName,
            'composer.lock' => $hasher->hash($root . '/composer.lock'),
            'phpunit' => $config->configurationFile !== null ? $hasher->hash($config->configurationFile) : null,
            'watched' => self::watchedHashes($config, $hasher, $root),
        ];

        return hash('xxh128', (string) json_encode($parts));
    }

    /**
     * @return array<string, string|null>
     */
    private static function watchedHashes(Config $config, FileHasher $hasher, string $root): array
    {
        $hashes = [];

        foreach ($config->watchedPaths as $pattern) {
            $matches = glob($root . '/' . ltrim($pattern, '/')) ?: [];

            foreach ($matches as $path) {
                if (is_file($path)) {
                    $hashes[$path] = $hasher->hash($path);
                }
            }
        }

        ksort($hashes);

        return $hashes;
    }
}
