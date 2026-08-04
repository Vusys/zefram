<?php

declare(strict_types=1);

namespace Vusys\Zefram\Laravel;

use Illuminate\Support\ServiceProvider;
use Vusys\Zefram\Laravel\Commands\ClearCommand;
use Vusys\Zefram\Laravel\Commands\StatsCommand;

/**
 * Optional — pure DX, no change to the core skip mechanism. Wraps the existing
 * vendor/bin/zefram CLI as artisan commands (see #52). Autodiscovered via
 * composer.json's extra.laravel.providers; no runtime illuminate/* dependency is added to
 * the package itself (illuminate/support stays require-dev-only — see #38, #52).
 */
final class ZeframServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearCommand::class,
                StatsCommand::class,
            ]);
        }
    }
}
