<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

use Vusys\Zefram\Coverage\CoverageDriver;

final class FakeDriver implements CoverageDriver
{
    public int $starts = 0;

    public int $stops = 0;

    /**
     * @param  list<string>  $files  absolute paths returned from every capture window
     */
    public function __construct(private array $files = []) {}

    /**
     * @param  list<string>  $files
     */
    public function willReturn(array $files): void
    {
        $this->files = $files;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function start(): void
    {
        $this->starts++;
    }

    public function stop(): array
    {
        $this->stops++;

        return $this->files;
    }
}
