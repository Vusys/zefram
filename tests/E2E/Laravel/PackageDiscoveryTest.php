<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\E2E\Laravel;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vusys\Zefram\Laravel\ZeframServiceProvider;

/**
 * Laravel's package discovery finds ZeframServiceProvider with nothing registered by hand
 * (#69). The testbench CLI boots a real Laravel app around this package and discovers the
 * root package from its composer.json extra.laravel.providers, the same entry Laravel reads
 * from vendor/composer/installed.json in a consuming app. APP_PACKAGES_CACHE points the
 * manifest at a temp file, so discovery runs fresh and the shared testbench skeleton's
 * cache is left alone.
 */
final class PackageDiscoveryTest extends TestCase
{
    private string $manifest;

    protected function setUp(): void
    {
        $this->manifest = sys_get_temp_dir().'/zefram-packages-'.bin2hex(random_bytes(6)).'.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifest)) {
            unlink($this->manifest);
        }
    }

    public function test_artisan_discovers_the_zefram_commands_without_manual_registration(): void
    {
        $output = $this->testbench('list', '--raw');

        $this->assertMatchesRegularExpression('/^zefram:clear\s/m', $output);
        $this->assertMatchesRegularExpression('/^zefram:stats\s/m', $output);

        $manifest = require $this->manifest;
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('vusys/zefram', $manifest);
        $this->assertSame(['providers' => [ZeframServiceProvider::class]], $manifest['vusys/zefram']);
    }

    private function testbench(string $command, string $option): string
    {
        $root = dirname(__DIR__, 3);

        $process = proc_open(
            [PHP_BINARY, $root.'/vendor/bin/testbench', $command, $option],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            [...getenv(), 'APP_PACKAGES_CACHE' => $this->manifest],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('could not start vendor/bin/testbench');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stdout.$stderr);

        return $stdout;
    }
}
