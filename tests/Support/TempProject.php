<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A throwaway project root on disk: source files to hash, a cache directory to fill.
 */
final readonly class TempProject
{
    public string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/zefram-test-'.bin2hex(random_bytes(8));

        mkdir($this->root, 0775, true);
    }

    public function write(string $relative, string $content): string
    {
        $path = $this->root.'/'.$relative;
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, $content);

        return $path;
    }

    public function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    public function destroy(): void
    {
        if (! is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            assert($item instanceof SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->root);
    }
}
