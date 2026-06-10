<?php

declare(strict_types=1);

namespace Vusys\Zefram\PhpUnit\Subscriber;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber as ExecutionFinishedSubscriberInterface;
use Vusys\Zefram\Engine;

final readonly class ExecutionFinishedSubscriber implements ExecutionFinishedSubscriberInterface
{
    public function __construct(private Engine $engine)
    {
    }

    public function notify(ExecutionFinished $event): void
    {
        $summary = $this->engine->finish();

        if ($summary !== null) {
            print PHP_EOL . $summary . PHP_EOL;
        }
    }
}
