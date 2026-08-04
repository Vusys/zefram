<?php

declare(strict_types=1);

namespace Vusys\Zefram\PhpUnit\Subscriber;

use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;
use Vusys\Zefram\Engine;
use Vusys\Zefram\PhpUnit\TestIdentity;

final readonly class TestPassedSubscriber implements PassedSubscriber
{
    public function __construct(private Engine $engine) {}

    public function notify(Passed $event): void
    {
        $identity = TestIdentity::fromTest($event->test());

        if ($identity !== null) {
            $this->engine->recordPassed(...$identity);
        }
    }
}
