<?php

declare(strict_types=1);

namespace Vusys\Zefram\Coverage;

final class NullDriver implements CoverageDriver
{
    public function name(): string
    {
        return 'null';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function start(): void {}

    public function stop(): array
    {
        return [];
    }
}
