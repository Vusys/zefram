<?php

declare(strict_types=1);

namespace Vusys\Zefram\Coverage;

interface CoverageDriver
{
    public function name(): string;

    public function isAvailable(): bool;

    public function start(): void;

    /**
     * Stop the capture window and return the absolute paths of every file executed within it.
     *
     * @return list<string>
     */
    public function stop(): array;
}
