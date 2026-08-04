<?php

declare(strict_types=1);

namespace Vusys\Zefram\Console;

/**
 * The CLI runs standalone, outside any PHPUnit process — there's no Configuration object to
 * hand it a resolved cacheDirectory the way ZeframExtension gets one. Instead this reads the
 * same value straight out of the project's phpunit.xml (the <bootstrap> parameter
 * ZeframExtension itself is registered with), falling back to Config's own '.zefram' default
 * when there's no configuration file or no parameter override.
 */
final class ProjectConfig
{
    private const EXTENSION_CLASS = 'Vusys\\Zefram\\PhpUnit\\ZeframExtension';

    private const DEFAULT_CACHE_DIRECTORY = '.zefram';

    public static function cacheDirectory(string $projectRoot): string
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $candidate) {
            $path = rtrim($projectRoot, '/') . '/' . $candidate;

            if (!is_file($path)) {
                continue;
            }

            $value = self::readCacheDirectoryParameter($path);

            if ($value !== null) {
                return $value;
            }
        }

        return self::DEFAULT_CACHE_DIRECTORY;
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

    private static function readCacheDirectoryParameter(string $configurationFile): ?string
    {
        $xml = @simplexml_load_file($configurationFile);

        if ($xml === false) {
            return null;
        }

        foreach ($xml->extensions->bootstrap ?? [] as $bootstrap) {
            if ((string) $bootstrap['class'] !== self::EXTENSION_CLASS) {
                continue;
            }

            foreach ($bootstrap->parameter ?? [] as $parameter) {
                if ((string) $parameter['name'] === 'cacheDirectory') {
                    $value = (string) $parameter['value'];

                    return $value === '' ? null : $value;
                }
            }
        }

        return null;
    }
}
