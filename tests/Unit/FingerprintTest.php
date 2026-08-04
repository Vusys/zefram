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
        // setUp() may throw before $project is assigned — tearDown() still runs, so guard
        // the typed property (see EngineTest for the same pattern).
        if (isset($this->project)) {
            $this->project->destroy();
        }
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

    public function testRecursiveGlobsMatchNestedFiles(): void
    {
        $this->project->write('resources/views/welcome.blade.php', '<p>hi</p>');
        $this->project->write('resources/views/crew/index.blade.php', '<p>crew</p>');

        $before = $this->compute(watched: ['resources/views/**/*.blade.php']);

        $this->project->write('resources/views/crew/index.blade.php', '<p>more crew</p>');

        $this->assertNotSame($before, $this->compute(watched: ['resources/views/**/*.blade.php']));
    }

    public function testRecursiveGlobIgnoresNonMatchingNestedFiles(): void
    {
        $this->project->write('config/app.php', '<?php return [];');
        $this->project->write('config/nested/deep.php', '<?php return [];');

        $before = $this->compute(watched: ['config/**']);

        $this->project->write('resources/views/welcome.blade.php', 'not watched');

        $this->assertSame($before, $this->compute(watched: ['config/**']));

        $this->project->write('config/nested/deep.php', '<?php return ["changed" => 1];');

        $this->assertNotSame($before, $this->compute(watched: ['config/**']));
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
