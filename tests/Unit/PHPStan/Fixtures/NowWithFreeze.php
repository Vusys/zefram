<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use PHPUnit\Framework\TestCase;

final class NowWithFreeze extends TestCase
{
    public function test_uses_now_after_freezing(): void
    {
        $this->freezeTime();

        $timestamp = now();

        $this->assertNotNull($timestamp);
    }

    private function freezeTime(): void
    {
        // Stands in for Illuminate\Foundation\Testing\Concerns\InteractsWithTime::freezeTime()
        // — the rule matches on the method name alone, not the declaring class.
    }
}
