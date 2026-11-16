<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

use RuntimeException;

/**
 * Shells out to a child `vendor/bin/phpunit` process (the outer project's own installed
 * PHPUnit, not a copy) with the pcov ini flags validated during the M0 spike, against
 * whatever project root/config the caller has already prepared. Generic: both the dogfood
 * harness (#2) and the generator-based E2E test (#23) use this rather than duplicating
 * proc_open/JUnit-parsing logic.
 */
final readonly class ChildPhpUnitProcess
{
    public function __construct(private string $phpBinary = PHP_BINARY) {}

    public static function pcovAvailable(): bool
    {
        return extension_loaded('pcov');
    }

    /**
     * @param  list<string>  $extraArgs
     * @param  list<string>  $phpIni  extra `-d` settings, applied after the pcov defaults
     */
    public function run(string $cwd, string $configFile, array $extraArgs = [], array $phpIni = []): ChildRunResult
    {
        $outerRoot = dirname(__DIR__, 2);
        $phpunitBinary = $outerRoot.'/vendor/bin/phpunit';

        if (! is_file($phpunitBinary)) {
            throw new RuntimeException("Zefram dogfood: outer phpunit binary not found at {$phpunitBinary}");
        }

        $junitPath = $cwd.'/junit-'.bin2hex(random_bytes(6)).'.xml';

        $command = [
            $this->phpBinary,
            '-d', 'pcov.enabled=1',
            '-d', 'pcov.directory='.$cwd,
            '-d', 'pcov.exclude=~/(vendor)/~',
            ...array_merge(...array_map(static fn (string $ini): array => ['-d', $ini], $phpIni)),
            $phpunitBinary,
            '-c', $configFile,
            '--log-junit', $junitPath,
            ...$extraArgs,
        ];

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Zefram dogfood: failed to start child phpunit process');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $outcomes = is_file($junitPath) ? JUnitReport::parse($junitPath) : [];

        if (is_file($junitPath)) {
            unlink($junitPath);
        }

        return new ChildRunResult($exitCode, $stdout, $stderr, $outcomes);
    }
}
