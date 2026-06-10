<?php

declare(strict_types=1);

namespace Vusys\Zefram\PhpUnit\Subscriber;

use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use Vusys\Zefram\Engine;
use Vusys\Zefram\PhpUnit\TestIdentity;

final readonly class TestSkippedSubscriber implements SkippedSubscriber
{
    public function __construct(private Engine $engine)
    {
    }

    public function notify(Skipped $event): void
    {
        $identity = TestIdentity::fromTest($event->test());

        if ($identity !== null) {
            $this->engine->recordSkipped(...$identity);
        }
    }
}
