<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Paratest;

final class ParatestTest extends TestCase
{
    private bool|string $originalTestToken;

    protected function setUp(): void
    {
        $this->originalTestToken = getenv('TEST_TOKEN');
    }

    protected function tearDown(): void
    {
        if ($this->originalTestToken === false) {
            putenv('TEST_TOKEN');
        } else {
            putenv('TEST_TOKEN=' . $this->originalTestToken);
        }
    }

    public function testDetectsAWorkerWhenTestTokenIsSet(): void
    {
        putenv('TEST_TOKEN=1');

        $this->assertTrue(Paratest::isWorker());
    }

    public function testNotAWorkerWhenTestTokenIsUnset(): void
    {
        putenv('TEST_TOKEN'); // unset

        $this->assertFalse(Paratest::isWorker());
    }

    public function testDetectsAWorkerEvenWhenTestTokenIsZero(): void
    {
        // paratest sets it to a worker index — 0 is a valid token (falsy string, but present)
        // and must still count as set; this is exactly why isWorker() checks !== false
        // rather than a truthiness check.
        putenv('TEST_TOKEN=0');

        $this->assertTrue(Paratest::isWorker());
    }
}
