<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit;

use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Config;
use Vusys\Zefram\Engine;
use Vusys\Zefram\Hash\FileHasher;
use Vusys\Zefram\Store\Store;
use Vusys\Zefram\Tests\Support\FakeDriver;
use Vusys\Zefram\Tests\Support\TempProject;
use Vusys\Zefram\Zefram;
use Vusys\Zefram\ZeframAware;

/**
 * Direct coverage of the trait itself — everywhere else it's only exercised indirectly, as
 * generated source in the e2e/dogfood suites (see #20's "Two cooperating pieces"), which
 * PHPStan can't see through. Exercises setUp()/zeframAfter() straight off a fixture instance
 * rather than through a full PHPUnit TestRunner, since ZeframAwareFixture's own protected
 * setUp() is exactly what's under test.
 */
final class ZeframAwareTest extends TestCase
{
    private const TEST_CLASS = ZeframAwareFixture::class;

    private TempProject $project;

    private FakeDriver $driver;

    protected function setUp(): void
    {
        $this->project = new TempProject;
        $this->project->write('tests/Fixture/ZeframAwareFixtureTest.php', '<?php class ZeframAwareFixtureTest {}');
        $this->driver = new FakeDriver([$this->project->path('tests/Fixture/ZeframAwareFixtureTest.php')]);
    }

    protected function tearDown(): void
    {
        Zefram::reset();

        if (isset($this->project)) {
            $this->project->destroy();
        }
    }

    public function test_set_up_opens_a_capture_window_that_records_a_real_pass(): void
    {
        $engine = $this->engine();
        Zefram::boot($engine);

        $fixture = new ZeframAwareFixture('testEngage');
        $fixture->exerciseSetUp(); // trait: zeframSetUp() then parent::setUp() -> opens the window on $engine
        $fixture->exerciseAfter(); // #[After]: zeframAfter() -> stopCapture() on the same $engine

        $this->assertFalse($fixture->exerciseZeframSkipped(), 'a test that ran was not skipped by zefram');

        // Prove the window genuinely captured files: record the pass through the same engine
        // instance the trait just drove, then ask a *fresh* Engine instance pointed at the
        // same store (simulating a new process) whether that test is now skip-eligible.
        $engine->recordPassed(self::TEST_CLASS, 'testEngage', '');
        $engine->finish();

        $this->assertTrue($this->engine()->shouldSkip(self::TEST_CLASS, 'testEngage', ''));
    }

    public function test_set_up_skips_and_never_opens_a_capture_window_on_a_second_pass(): void
    {
        $priorRun = $this->engine();
        $priorRun->startCapture(self::TEST_CLASS, 'testEngage', '');
        $priorRun->stopCapture();
        $priorRun->recordPassed(self::TEST_CLASS, 'testEngage', '');
        $priorRun->finish();

        Zefram::boot($this->engine()); // fresh instance, same store: simulates a new process

        $fixture = new ZeframAwareFixture('testEngage');

        $this->expectException(SkippedWithMessageException::class);
        $this->expectExceptionMessage('Zefram: unchanged since last pass');

        try {
            $fixture->exerciseSetUp();
        } finally {
            // After-hooks run for skipped tests too (see #20) — must tolerate the
            // never-opened window without throwing.
            $fixture->exerciseAfter();

            // tearDown() reads this to skip framework cleanup (#87)
            $this->assertTrue($fixture->exerciseZeframSkipped());
        }
    }

    private function engine(): Engine
    {
        $config = new Config(enabled: true, projectRoot: $this->project->root);

        return new Engine($config, $this->driver, new Store($config->storePath().'/fp'), new FileHasher, 'fp');
    }
}

/**
 * @internal fixture only — never auto-discovered as a real test (no test* method of its
 * own), just a place to exercise the trait's protected methods from ZeframAwareTest above.
 */
final class ZeframAwareFixture extends TestCase
{
    use ZeframAware;

    public function exerciseSetUp(): void
    {
        $this->setUp();
    }

    public function exerciseAfter(): void
    {
        $this->zeframAfter();
    }

    public function exerciseZeframSkipped(): bool
    {
        return $this->zeframSkipped();
    }
}
