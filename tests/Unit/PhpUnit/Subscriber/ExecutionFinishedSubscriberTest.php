<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PhpUnit\Subscriber;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Vusys\Zefram\Config;
use Vusys\Zefram\Engine;
use Vusys\Zefram\Hash\FileHasher;
use Vusys\Zefram\PhpUnit\Subscriber\ExecutionFinishedSubscriber;
use Vusys\Zefram\Store\Store;
use Vusys\Zefram\Tests\Support\FakeDriver;
use Vusys\Zefram\Tests\Support\TempProject;

final class ExecutionFinishedSubscriberTest extends TestCase
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

    public function test_prints_the_summary_line_outside_paratest(): void
    {
        $engine = $this->engine(paratestWorker: false);
        $engine->shouldSkip('App\\Tests\\AlphaTest', 'testOne', '');

        $output = $this->notify($engine);

        $this->assertStringContainsString('Zefram: skipped', $output);
    }

    public function test_suppresses_the_summary_line_under_paratest(): void
    {
        // #12: every paratest worker independently fires ExecutionFinished — with concurrent
        // workers, each printing its own summary line mangles the terminal output.
        $engine = $this->engine(paratestWorker: true);
        $engine->shouldSkip('App\\Tests\\AlphaTest', 'testOne', '');

        $output = $this->notify($engine);

        $this->assertSame('', $output, 'a paratest worker must never print its own summary line');
    }

    public function test_still_flushes_the_store_under_paratest_despite_suppressing_output(): void
    {
        // suppressing the print must not skip the underlying finish() work (flush etc.)
        $filePath = $this->project->write('src/A.php', '<?php class A {}');
        $driver = new FakeDriver([$filePath]);

        $engine = $this->engine(paratestWorker: true, driver: $driver);
        $engine->shouldSkip('App\\Tests\\AlphaTest', 'testOne', '');
        $engine->startCapture('App\\Tests\\AlphaTest', 'testOne', '');
        $engine->stopCapture();
        $engine->recordPassed('App\\Tests\\AlphaTest', 'testOne', '');

        $this->notify($engine);

        $this->assertTrue(
            $this->engine(paratestWorker: true, driver: $driver)->shouldSkip('App\\Tests\\AlphaTest', 'testOne', ''),
            'the record must have been persisted to disk despite the print being suppressed',
        );
    }

    private function notify(Engine $engine): string
    {
        $subscriber = new ExecutionFinishedSubscriber($engine);

        ob_start();
        $subscriber->notify($this->event());

        return (string) ob_get_clean();
    }

    private function engine(bool $paratestWorker, ?FakeDriver $driver = null): Engine
    {
        $config = new Config(
            enabled: true,
            projectRoot: $this->project->root,
            paratestWorker: $paratestWorker,
        );

        return new Engine($config, $driver ?? new FakeDriver, new Store($config->cachePath().'/fp'), new FileHasher, 'fp');
    }

    /**
     * The subscriber never reads anything off the event itself, only calls
     * Engine::finish() — safe to construct without its (nested, involved) Telemetry\Info.
     */
    private function event(): ExecutionFinished
    {
        /** @var ExecutionFinished $event */
        $event = (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();

        return $event;
    }
}
