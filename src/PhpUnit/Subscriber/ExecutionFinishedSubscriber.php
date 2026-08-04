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

        // Every paratest worker independently fires this event — with concurrent workers,
        // each printing its own summary line interleaves and mangles the terminal output
        // (#12). finish() still runs in full (flush, pruning already gated off under
        // paratest via Engine::isFullRun() — see #11); only the print is suppressed.
        if ($summary !== null && !$this->engine->isParatestWorker()) {
            print PHP_EOL . $summary . PHP_EOL;
        }
    }
}
