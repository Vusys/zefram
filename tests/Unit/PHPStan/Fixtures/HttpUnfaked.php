<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;

final class HttpUnfaked extends TestCase
{
    public function test_makes_an_unfaked_http_call(): void
    {
        $response = Http::get('https://example.test');

        $this->assertNotNull($response);
    }
}
