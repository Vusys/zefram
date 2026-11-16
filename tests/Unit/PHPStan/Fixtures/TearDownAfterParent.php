<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\ZeframAware;

final class TearDownAfterParent extends TestCase
{
    use ZeframAware;

    protected function tearDown(): void
    {
        parent::tearDown();

        app()->forgetInstances();
    }
}
