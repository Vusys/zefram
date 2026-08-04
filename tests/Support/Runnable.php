<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

/**
 * Marker interface implemented by GraphGenerator's generated node classes. Instances are
 * created from a runtime class-string (GraphGenerator::classFqcn()), which PHPStan can only
 * ever see as the generic `object` type — this gives call sites something real to narrow to
 * (via `assert($instance instanceof Runnable)`) instead of a plain `object`.
 */
interface Runnable
{
    public function run(): void;
}
