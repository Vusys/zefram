<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\TestCase;

final class TearDownWithoutZefram extends TestCase
{
    protected function tearDown(): void
    {
        Storage::disk('data')->delete('fixture.csv');

        parent::tearDown();
    }
}
