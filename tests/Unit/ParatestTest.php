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
            putenv('TEST_TOKEN='.$this->originalTestToken);
        }
    }

    public function test_detects_a_worker_when_test_token_is_set(): void
    {
        putenv('TEST_TOKEN=1');

        $this->assertTrue(Paratest::isWorker());
    }

    public function test_not_a_worker_when_test_token_is_unset(): void
    {
        putenv('TEST_TOKEN'); // unset

        $this->assertFalse(Paratest::isWorker());
    }

    public function test_detects_a_worker_even_when_test_token_is_zero(): void
    {
        // paratest sets it to a worker index — 0 is a valid token (falsy string, but present)
        // and must still count as set; this is exactly why isWorker() checks !== false
        // rather than a truthiness check.
        putenv('TEST_TOKEN=0');

        $this->assertTrue(Paratest::isWorker());
    }
}
