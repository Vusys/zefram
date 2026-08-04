<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

final class HttpFaked extends TestCase
{
    public function test_makes_a_faked_http_call(): void
    {
        Http::fake();

        $response = Http::get('https://example.test');

        $this->assertNotNull($response);
    }
}
