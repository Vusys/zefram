<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Hash\FileHasher;
use Vusys\Zefram\Tests\Support\TempProject;

final class FileHasherTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject;
    }

    protected function tearDown(): void
    {
        // setUp() may throw before $project is assigned — tearDown() still runs, so guard
        // the typed property (see EngineTest for the same pattern).
        if (isset($this->project)) {
            $this->project->destroy();
        }
    }

    public function test_hashes_file_content(): void
    {
        $path = $this->project->write('a.php', '<?php echo 1;');

        $hasher = new FileHasher;

        $this->assertSame(hash('xxh128', '<?php echo 1;'), $hasher->hash($path));
    }

    public function test_returns_null_for_missing_file(): void
    {
        $hasher = new FileHasher;

        $this->assertNull($hasher->hash($this->project->path('missing.php')));
    }

    public function test_memoizes_per_instance(): void
    {
        $path = $this->project->write('a.php', 'original');

        $hasher = new FileHasher;
        $first = $hasher->hash($path);

        file_put_contents($path, 'changed on disk');

        $this->assertSame($first, $hasher->hash($path), 'second read must come from the memo, not disk');
        $this->assertNotSame($first, (new FileHasher)->hash($path), 'a fresh hasher sees the new content');
    }
}
