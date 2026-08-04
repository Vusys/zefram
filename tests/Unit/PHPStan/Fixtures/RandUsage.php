<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use PHPUnit\Framework\TestCase;

/**
 * No Laravel/Larastan-specific symbol anywhere in this file — exercises the
 * framework-agnostic path independently.
 */
final class RandUsage extends TestCase
{
    public function test_uses_rand(): void
    {
        $value = random_int(1, 10);

        $this->assertGreaterThanOrEqual(1, $value);
    }
}
