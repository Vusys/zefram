<?php

declare(strict_types=1);

namespace Vusys\Zefram\PhpUnit\Subscriber;

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use Vusys\Zefram\Engine;
use Vusys\Zefram\PhpUnit\TestIdentity;

final readonly class TestErroredSubscriber implements ErroredSubscriber
{
    public function __construct(private Engine $engine)
    {
    }

    public function notify(Errored $event): void
    {
        $identity = TestIdentity::fromTest($event->test());

        if ($identity !== null) {
            $this->engine->recordNotPassed(...$identity);
        }
    }
}
