<?php

declare(strict_types=1);

namespace Vusys\Zefram;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
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
            foreach (self::expand($root, ltrim($pattern, '/')) as $path) {
                $hashes[$path] = $hasher->hash($path);
            }
        }

        ksort($hashes);

        return $hashes;
    }

    /**
     * Expand a glob pattern relative to the root. Patterns containing ** match across
     * directory separators (config/**, resources/views/**​/*.blade.php); plain patterns
     * fall through to glob().
     *
     * @return list<string> absolute paths of matched files
     */
    private static function expand(string $root, string $pattern): array
    {
        if (!str_contains($pattern, '**')) {
            return array_values(array_filter(glob($root . '/' . $pattern) ?: [], is_file(...)));
        }

        // walk only the fixed directory prefix before the first wildcard
        $fixed = substr($pattern, 0, strcspn($pattern, '*?'));
        $lastSlash = strrpos($fixed, '/');
        $baseDir = $root . ($lastSlash === false ? '' : '/' . substr($fixed, 0, $lastSlash));

        if (!is_dir($baseDir)) {
            return [];
        }

        $regex = '#^' . str_replace(
            ['\*\*/', '\*\*', '\*', '\?'],
            ['(?:.*/)?', '.*', '[^/]*', '[^/]'],
            preg_quote($pattern, '#'),
        ) . '$#';

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];

        foreach ($iterator as $file) {
            assert($file instanceof SplFileInfo);

            if (!$file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            if (preg_match($regex, $relative) === 1) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
