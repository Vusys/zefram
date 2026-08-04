<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Console\Application;
use Vusys\Zefram\Tests\Support\TempProject;

final class ApplicationTest extends TestCase
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

    public function test_dispatches_to_the_clear_command(): void
    {
        $this->project->write('.zefram/fp/tests/fake.json', '{}');

        $app = new Application($this->project->root);

        ob_start();
        $exitCode = $app->run(['bin/zefram', 'clear']);
        ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertDirectoryDoesNotExist($this->project->path('.zefram'));
    }

    public function test_prints_usage_and_fails_with_no_command(): void
    {
        $app = new Application($this->project->root);
        $stderr = $this->memoryStream();

        $exitCode = $app->run(['bin/zefram'], $stderr);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Usage: zefram <command>', $this->contentsOf($stderr));
    }

    public function test_prints_usage_and_fails_on_an_unknown_command(): void
    {
        $app = new Application($this->project->root);
        $stderr = $this->memoryStream();

        $exitCode = $app->run(['bin/zefram', 'nonexistent'], $stderr);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('zefram: unknown command "nonexistent"', $this->contentsOf($stderr));
    }

    public function test_defaults_project_root_to_the_current_working_directory_when_unspecified(): void
    {
        // must not throw when constructed with no argument
        $app = new Application;

        $this->assertSame(1, $app->run(['bin/zefram', 'nonexistent'], $this->memoryStream()));
    }

    /**
     * @return resource
     */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'w+');

        assert($stream !== false);

        return $stream;
    }

    /**
     * @param  resource  $stream
     */
    private function contentsOf($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
