<?php

declare(strict_types=1);

namespace Vusys\Zefram\Console;

interface Command
{
    /**
     * @param  list<string>  $args  arguments after the command name
     */
    public function run(array $args): int;
}
