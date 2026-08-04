<?php

declare(strict_types=1);

namespace Vusys\Zefram\Console\Command;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Vusys\Zefram\Console\Command;
use Vusys\Zefram\Console\ProjectConfig;

/**
 * `zefram clear` — deletes the entire cache directory, always everything, no
 * fingerprint-scoping argument (decided: #6's automatic fingerprint-directory pruning
 * already handles removing stale fingerprints during normal runs, so a scoped manual clear
 * isn't pulling enough weight to justify the extra surface).
 */
final readonly class ClearCommand implements Command
{
    public function __construct(private string $projectRoot) {}

    public function run(array $args): int
    {
        $cachePath = ProjectConfig::cachePath($this->projectRoot);

        if (! is_dir($cachePath)) {
            echo "zefram: nothing to clear ({$cachePath} does not exist)\n";

            return 0;
        }

        $this->removeDirectory($cachePath);

        echo "zefram: cleared {$cachePath}\n";

        return 0;
    }

    private function removeDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            assert($item instanceof SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
