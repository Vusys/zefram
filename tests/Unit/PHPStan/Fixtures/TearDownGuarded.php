<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;
use Vusys\Zefram\ZeframAware;

final class TearDownGuarded extends TestCase
{
    use ZeframAware;

    protected function tearDown(): void
    {
        if ($this->zeframSkipped()) {
            parent::tearDown();

            return;
        }

        Storage::disk('data')->delete('fixture.csv');

        parent::tearDown();
    }
}
