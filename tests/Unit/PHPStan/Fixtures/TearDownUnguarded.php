<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;
use Vusys\Zefram\ZeframAware;

final class TearDownUnguarded extends TestCase
{
    use ZeframAware;

    protected function tearDown(): void
    {
        Storage::disk('data')->delete('fixture.csv');

        parent::tearDown();
    }
}
