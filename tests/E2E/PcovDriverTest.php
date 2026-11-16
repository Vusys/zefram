<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Coverage\PcovDriver;
use Vusys\Zefram\Hash\FileHasher;
use Vusys\Zefram\Store\TestRecord;

/**
 * The real pcov driver on its own, without the child-process harness (#61). In the e2e
 * suite rather than unit, because Infection collects coverage with pcov over the unit
 * suite and this test's clear() would wipe it.
 */
final class PcovDriverTest extends TestCase
{
    private PcovDriver $driver;

    protected function setUp(): void
    {
        if (! extension_loaded('pcov') || ! filter_var(ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('needs pcov loaded with pcov.enabled=1');
        }

        $this->driver = new PcovDriver;
    }

    public function test_it_reports_itself_as_pcov_and_available(): void
    {
        $this->assertSame('pcov', $this->driver->name());
        $this->assertTrue($this->driver->isAvailable());
    }

    public function test_a_window_returns_the_files_executed_in_it(): void
    {
        $this->driver->start();
        (new FileHasher)->hash(__FILE__);
        $files = $this->driver->stop();

        $this->assertContains($this->src('Hash/FileHasher.php'), $files);
        $this->assertNotContains($this->src('Store/TestRecord.php'), $files);
    }

    public function test_a_window_does_not_inherit_the_previous_one(): void
    {
        $this->driver->start();
        (new FileHasher)->hash(__FILE__);
        $this->driver->stop();

        $this->driver->start();
        $record = (new TestRecord([], 0.1, 'passed'))->toArray();
        $files = $this->driver->stop();

        $this->assertSame('passed', $record['result']);

        $this->assertContains($this->src('Store/TestRecord.php'), $files);
        $this->assertNotContains($this->src('Hash/FileHasher.php'), $files);
    }

    private function src(string $relative): string
    {
        return (string) realpath(dirname(__DIR__, 2).'/src/'.$relative);
    }
}
