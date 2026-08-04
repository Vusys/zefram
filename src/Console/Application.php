<?php

declare(strict_types=1);

namespace Vusys\Zefram\Console;

use Vusys\Zefram\Console\Command\ClearCommand;
use Vusys\Zefram\Console\Command\StatsCommand;

/**
 * A thin $argv dispatcher for `vendor/bin/zefram <command>` — no framework needed, PHPUnit
 * itself isn't a dependency for running this.
 */
final readonly class Application
{
    /** @var array<string, Command> */
    private array $commands;

    public function __construct(private string $projectRoot = '')
    {
        $projectRoot = $this->projectRoot !== '' ? $this->projectRoot : (string) getcwd();

        $this->commands = [
            'clear' => new ClearCommand($projectRoot),
            'stats' => new StatsCommand($projectRoot),
        ];
    }

    /**
     * @param  list<string>  $argv  the full $argv, including the script name at index 0
     */
    public function run(array $argv): int
    {
        $name = $argv[1] ?? null;

        if ($name === null) {
            fwrite(STDERR, $this->usage());

            return 1;
        }

        if (! isset($this->commands[$name])) {
            fwrite(STDERR, "zefram: unknown command \"{$name}\"\n\n".$this->usage());

            return 1;
        }

        return $this->commands[$name]->run(array_slice($argv, 2));
    }

    private function usage(): string
    {
        $lines = array_map(static fn (string $name): string => "  {$name}", array_keys($this->commands));

        return "Usage: zefram <command>\n\nAvailable commands:\n".implode("\n", $lines)."\n";
    }
}
