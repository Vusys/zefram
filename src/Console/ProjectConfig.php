<?php

declare(strict_types=1);

namespace Vusys\Zefram\Console;

use Vusys\Zefram\Config;
use Vusys\Zefram\Engine;
use Vusys\Zefram\Fingerprint;
use Vusys\Zefram\Hash\FileHasher;

/**
 * The CLI runs standalone, outside any PHPUnit process — there's no Configuration object to
 * hand it a resolved cacheDirectory (or, for `zefram stats`, a fingerprint) the way
 * ZeframExtension gets one. Instead this reads the same parameters straight out of the
 * project's phpunit.xml (the <bootstrap> parameters ZeframExtension itself is registered
 * with) and recomputes the fingerprint the same way Fingerprint::compute() does, falling
 * back to Config's own defaults when there's no configuration file or no override.
 */
final class ProjectConfig
{
    private const EXTENSION_CLASS = 'Vusys\\Zefram\\PhpUnit\\ZeframExtension';

    private const DEFAULT_CACHE_DIRECTORY = '.zefram';

    public static function configurationFile(string $projectRoot): ?string
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $candidate) {
            $path = rtrim($projectRoot, '/') . '/' . $candidate;

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function cacheDirectory(string $projectRoot): string
    {
        return self::parameter($projectRoot, 'cacheDirectory') ?? self::DEFAULT_CACHE_DIRECTORY;
    }

    /**
     * Mirrors Config::cachePath()'s absolute-vs-relative resolution.
     */
    public static function cachePath(string $projectRoot): string
    {
        $cacheDirectory = self::cacheDirectory($projectRoot);

        if (str_starts_with($cacheDirectory, '/')) {
            return $cacheDirectory;
        }

        return rtrim($projectRoot, '/') . '/' . $cacheDirectory;
    }

    /**
     * @return list<string>
     */
    public static function watchedPaths(string $projectRoot): array
    {
        $value = self::parameter($projectRoot, 'watchedPaths');

        if ($value === null) {
            return [];
        }

        $values = array_map(trim(...), explode(',', $value));

        return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
    }

    /**
     * The fingerprint a real ZeframExtension-driven run would compute right now, in this
     * process — same inputs (composer.lock, resolved phpunit.xml content, PHP version,
     * package version, watched paths), driver name inferred from whether pcov is loaded in
     * the CLI's own PHP process (the same signal Engine::create() uses).
     */
    public static function currentFingerprint(string $projectRoot): string
    {
        $config = new Config(
            enabled: true,
            projectRoot: $projectRoot,
            cacheDirectory: self::cacheDirectory($projectRoot),
            watchedPaths: self::watchedPaths($projectRoot),
            configurationFile: self::configurationFile($projectRoot),
        );

        $driverName = extension_loaded('pcov') ? 'pcov' : 'null';

        return Fingerprint::compute($config, $driverName, Engine::VERSION, new FileHasher());
    }

    public static function currentFingerprintPath(string $projectRoot): string
    {
        return rtrim(self::cachePath($projectRoot), '/') . '/' . self::currentFingerprint($projectRoot);
    }

    private static function parameter(string $projectRoot, string $name): ?string
    {
        $configurationFile = self::configurationFile($projectRoot);

        if ($configurationFile === null) {
            return null;
        }

        $xml = @simplexml_load_file($configurationFile);

        if ($xml === false) {
            return null;
        }

        foreach ($xml->extensions->bootstrap ?? [] as $bootstrap) {
            if ((string) $bootstrap['class'] !== self::EXTENSION_CLASS) {
                continue;
            }

            foreach ($bootstrap->parameter ?? [] as $parameter) {
                if ((string) $parameter['name'] === $name) {
                    $value = (string) $parameter['value'];

                    return $value === '' ? null : $value;
                }
            }
        }

        return null;
    }
}
