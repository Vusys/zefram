<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Attribute\NeverSkip;

final class NeverSkipMethod extends TestCase
{
    #[NeverSkip]
    public function test_uses_now_but_is_marked_never_skip(): void
    {
        $timestamp = now();

        $this->assertNotNull($timestamp);
    }
}
