<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan\Fixtures;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\ZeframAware;

abstract class ZeframBase extends TestCase
{
    use ZeframAware;

    /** @var object */
    protected $app;
}
