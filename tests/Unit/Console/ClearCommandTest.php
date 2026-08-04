<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Console\Command\ClearCommand;
use Vusys\Zefram\Tests\Support\TempProject;

final class ClearCommandTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject;
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->destroy();
        }
    }

    public function test_clears_the_default_cache_directory(): void
    {
        $this->project->write('.zefram/fp/tests/fake.json', '{}');

        $exitCode = $this->runClear();

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryDoesNotExist($this->project->path('.zefram'));
    }

    public function test_is_a_no_op_when_the_cache_directory_does_not_exist(): void
    {
        $exitCode = $this->runClear();

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryDoesNotExist($this->project->path('.zefram'));
    }

    public function test_reads_a_custom_cache_directory_from_php_unit_xml(): void
    {
        $this->project->write('phpunit.xml', <<<'XML'
<?xml version="1.0"?>
<phpunit>
    <extensions>
        <bootstrap class="Vusys\Zefram\PhpUnit\ZeframExtension">
            <parameter name="cacheDirectory" value="custom-cache-dir"/>
        </bootstrap>
    </extensions>
</phpunit>
XML);
        $this->project->write('custom-cache-dir/fp/tests/fake.json', '{}');
        $this->project->write('.zefram/fp/tests/fake.json', '{}'); // must be left alone

        $exitCode = $this->runClear();

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryDoesNotExist($this->project->path('custom-cache-dir'));
        $this->assertDirectoryExists($this->project->path('.zefram'), 'the default directory was never the configured one — must be untouched');
    }

    public function test_outputs_what_it_did(): void
    {
        $this->project->write('.zefram/fp/tests/fake.json', '{}');

        ob_start();
        $this->runClear();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('cleared', $output);
        $this->assertStringContainsString($this->project->path('.zefram'), $output);
    }

    private function runClear(): int
    {
        return (new ClearCommand($this->project->root))->run([]);
    }
}
