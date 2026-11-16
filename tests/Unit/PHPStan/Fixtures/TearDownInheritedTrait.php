<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

final class TearDownInheritedTrait extends ZeframBase
{
    protected function tearDown(): void
    {
        $this->app->flush();

        parent::tearDown();
    }
}
