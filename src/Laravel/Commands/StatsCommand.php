<?php

declare(strict_types=1);

namespace Vusys\Zefram\Laravel\Commands;

use Illuminate\Console\Command;
use Vusys\Zefram\Console\Command\StatsCommand as CoreStatsCommand;

/**
 * `php artisan zefram:stats [--json]` — a thin adapter over the same
 * Vusys\Zefram\Console\Command\StatsCommand the standalone `vendor/bin/zefram stats` CLI
 * calls (see #8/#9), so behavior can't drift between the two entry points. No reimplemented
 * logic here on purpose.
 */
final class StatsCommand extends Command
{
    protected $signature = 'zefram:stats {--json : Emit JSON instead of a text summary}';

    protected $description = 'Show Zefram cache statistics';

    public function handle(): int
    {
        $args = $this->option('json') ? ['--json'] : [];

        return (new CoreStatsCommand(base_path()))->run($args);
    }
}
