<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Config;
use Vusys\Zefram\Fingerprint;
use Vusys\Zefram\Hash\FileHasher;
use Vusys\Zefram\Tests\Support\TempProject;

final class FingerprintTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->write('composer.lock', '{"packages": []}');
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function testStableAcrossRuns(): void
    {
        $this->assertSame($this->compute(), $this->compute());
    }

    public function testChangesWhenComposerLockChanges(): void
    {
        $before = $this->compute();

        $this->project->write('composer.lock', '{"packages": ["different"]}');

        $this->assertNotSame($before, $this->compute());
    }

    public function testChangesWhenWatchedFileChanges(): void
    {
        $this->project->write('.env.testing', 'APP_ENV=testing');

        $before = $this->compute(watched: ['.env.testing']);

        $this->project->write('.env.testing', 'APP_ENV=testing2');

        $this->assertNotSame($before, $this->compute(watched: ['.env.testing']));
    }

    public function testWatchedGlobsMatchMultipleFiles(): void
    {
        $this->project->write('config/a.php', '<?php return [];');
        $this->project->write('config/b.php', '<?php return [];');

        $before = $this->compute(watched: ['config/*.php']);

        $this->project->write('config/b.php', '<?php return ["changed" => true];');

        $this->assertNotSame($before, $this->compute(watched: ['config/*.php']));
    }

    public function testChangesWithDriverName(): void
    {
        $this->assertNotSame($this->compute(driver: 'pcov'), $this->compute(driver: 'other'));
    }

    /**
     * @param list<string> $watched
     */
    private function compute(array $watched = [], string $driver = 'pcov'): string
    {
        $config = new Config(enabled: true, projectRoot: $this->project->root, watchedPaths: $watched);

        // a fresh hasher each time: the memo must not mask disk changes between computations
        return Fingerprint::compute($config, $driver, '0.1.0', new FileHasher());
    }
}
