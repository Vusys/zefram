<?php

declare(strict_types=1);

namespace Vusys\Zefram\PhpUnit\Subscriber;

use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\ConsideredRiskySubscriber;
use Vusys\Zefram\Engine;
use Vusys\Zefram\PhpUnit\TestIdentity;

final readonly class TestConsideredRiskySubscriber implements ConsideredRiskySubscriber
{
    public function __construct(private Engine $engine) {}

    public function notify(ConsideredRisky $event): void
    {
        $identity = TestIdentity::fromTest($event->test());

        if ($identity !== null) {
            $this->engine->recordNotPassed(...$identity);
        }
    }
}
