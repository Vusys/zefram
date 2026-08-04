<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use PHPUnit\Framework\TestCase;

final class NowWithoutFreeze extends TestCase
{
    public function test_uses_now_without_freezing(): void
    {
        $timestamp = now();

        $this->assertNotNull($timestamp);
    }
}
