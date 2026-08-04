<?php

declare(strict_types=1);

namespace Vusys\Zefram\Laravel\Commands;

use Illuminate\Console\Command;
use Vusys\Zefram\Console\Command\ClearCommand as CoreClearCommand;

/**
 * `php artisan zefram:clear` — a thin adapter over the same
 * Vusys\Zefram\Console\Command\ClearCommand the standalone `vendor/bin/zefram clear` CLI
 * calls (see #8/#9), so behavior can't drift between the two entry points. No reimplemented
 * logic here on purpose.
 */
final class ClearCommand extends Command
{
    protected $signature = 'zefram:clear';

    protected $description = "Delete Zefram's cache directory";

    public function handle(): int
    {
        return (new CoreClearCommand(base_path()))->run([]);
    }
}
