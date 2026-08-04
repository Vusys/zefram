<?php

declare(strict_types=1);

namespace Vusys\Zefram\PhpUnit\Subscriber;

use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;
use Vusys\Zefram\Engine;
use Vusys\Zefram\PhpUnit\TestIdentity;

final readonly class TestMarkedIncompleteSubscriber implements MarkedIncompleteSubscriber
{
    public function __construct(private Engine $engine) {}

    public function notify(MarkedIncomplete $event): void
    {
        $identity = TestIdentity::fromTest($event->test());

        if ($identity !== null) {
            $this->engine->recordNotPassed(...$identity);
        }
    }
}
