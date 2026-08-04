<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Turns a small, real orchestra/testbench-based fixture project into a runnable,
 * zefram-enabled PHPUnit project and runs it through a real child process, reusing
 * ChildPhpUnitProcess/JUnitReport exactly like DogfoodRunner (#2) and
 * GeneratedProjectRunner (#23) do. See #51: proves ZeframAware composes correctly with a
 * real Laravel TestCase and RefreshDatabase, not just a plain PHPUnit one.
 *
 * A real child process — not an in-process fixture — is necessary here specifically because
 * of pcov: this repo's own pcov.directory only instruments src/, so a fixture class has to
 * live under its own project root with its own pcov.directory override (which
 * ChildPhpUnitProcess already sets per spawn) for coverage capture-through-the-container
 * (assertion 4 in #51) to be provable for real, not asserted around.
 */
final readonly class LaravelProjectRunner
{
    use ReadsZeframShards;

    public string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/zefram-laravel-'.bin2hex(random_bytes(8));

        mkdir($this->root.'/src', 0775, true);
        mkdir($this->root.'/tests', 0775, true);
        mkdir($this->root.'/database/migrations', 0775, true);

        file_put_contents($this->root.'/src/WarpCoreService.php', $this->renderService());
        file_put_contents(
            $this->root.'/database/migrations/2024_01_01_000000_create_warp_cores_table.php',
            $this->renderMigration(),
        );
        file_put_contents($this->root.'/tests/TestbenchZeframTest.php', $this->renderTest());
        file_put_contents($this->root.'/tests/ArtisanCommandsTest.php', $this->renderArtisanCommandsTest());
        file_put_contents($this->bootstrapPath(), $this->renderBootstrap());
        file_put_contents($this->phpUnitConfigPath(), $this->renderPhpUnitConfig());
    }

    public static function pcovAvailable(): bool
    {
        return ChildPhpUnitProcess::pcovAvailable();
    }

    public function run(): ChildRunResult
    {
        return (new ChildPhpUnitProcess)->run($this->root, $this->phpUnitConfigPath());
    }

    public function cacheRoot(): string
    {
        return $this->root;
    }

    public function bootLogPath(): string
    {
        return $this->root.'/boot.log';
    }

    /**
     * How many times Laravel's own app boot (createApplication() -> defineEnvironment())
     * actually ran — the same "log write inside the path a skipped test must never reach"
     * proof technique used elsewhere in the e2e suites (see #22).
     */
    public function bootReachedCount(): int
    {
        if (! is_file($this->bootLogPath())) {
            return 0;
        }

        $lines = array_filter(
            explode("\n", (string) file_get_contents($this->bootLogPath())),
            static fn (string $l): bool => $l !== '',
        );

        return count($lines);
    }

    public function destroy(): void
    {
        if (! is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            assert($item instanceof SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->root);
    }

    private function bootstrapPath(): string
    {
        return $this->root.'/e2e-bootstrap.php';
    }

    private function phpUnitConfigPath(): string
    {
        return $this->root.'/phpunit.xml';
    }

    /**
     * A trivial service resolved through Laravel's container via constructor injection
     * (Illuminate\Contracts\Config\Repository, already bound by the framework) — not just
     * `new Foo()` — to prove pcov's file-level capture works the same whether code runs
     * directly or through Laravel's resolver (assertion 4 in #51).
     */
    private function renderService(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace Zefram\Generated\LaravelFixture;

use Illuminate\Contracts\Config\Repository;

final class WarpCoreService
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function designation(): string
    {
        $name = $this->config->get('app.name');

        return is_string($name) && $name !== '' ? $name : 'NCC-1701';
    }
}

PHP;
    }

    private function renderMigration(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warp_cores', function (Blueprint $table): void {
            $table->id();
            $table->string('designation');
        });
    }
};

PHP;
    }

    private function renderTest(): string
    {
        return <<<'PHP_WRAP'
        <?php
        
        declare(strict_types=1);
        
        namespace Zefram\Generated\LaravelFixture\Tests;
        
        use Illuminate\Foundation\Testing\RefreshDatabase;
        use Illuminate\Support\Facades\DB;
        use Illuminate\Support\Facades\Schema;
        use Orchestra\Testbench\TestCase as BaseTestCase;
        use Vusys\Zefram\ZeframAware;
        use Zefram\Generated\LaravelFixture\WarpCoreService;
        
        final class TestbenchZeframTest extends BaseTestCase
        {
            use ZeframAware;
            use RefreshDatabase;
        
            protected function defineEnvironment($app): void
            {
                // Only reachable if parent::setUp() (i.e. Testbench's real app boot) actually
                // runs — a skipped test throws out of ZeframAware::setUp() before parent::setUp()
                // is ever called, so this line never executes for a skipped test. See #22 for the
                // same proof technique against the plain-PHPUnit dogfood suite.
                file_put_contents(__DIR__.'/../boot.log', "booted\n", FILE_APPEND | LOCK_EX);
        
                $app['config']->set('database.default', 'testing');
                $app['config']->set('database.connections.testing', [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'prefix' => '',
                ]);
            }
        
            protected function defineDatabaseMigrations(): void
            {
                $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
            }
        
            public function testEngage(): void
            {
                // A real migration + a real query — not just "the process is fresh so it's empty
                // anyway" — Schema::hasTable() would be false if RefreshDatabase never migrated.
                $this->assertTrue(Schema::hasTable('warp_cores'), 'RefreshDatabase must have run the real migration before this test body executes');
        
                DB::table('warp_cores')->insert(['designation' => 'NCC-1701-D']);
                $this->assertSame(1, DB::table('warp_cores')->count());
        
                // Constructor-injected via the container, not `new WarpCoreService()` — proves
                // coverage capture sees code reached through Laravel's resolver too.
                $service = $this->app->make(WarpCoreService::class);
                $this->assertNotSame('', $service->designation());
            }
        }
        
        PHP_WRAP;
    }

    /**
     * Covers #52: the artisan commands must produce identical output to their
     * vendor/bin/zefram equivalents (both are thin adapters over the same
     * Vusys\Zefram\Console\Command\* classes — this proves they can't have drifted), and
     * autodiscovery-registers via getPackageProviders() the same way Laravel's real
     * package-discovery manifest would (see the docblock note on the harness limitation
     * this stands in for).
     */
    private function renderArtisanCommandsTest(): string
    {
        return <<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        namespace Zefram\Generated\LaravelFixture\Tests;

        use Illuminate\Support\Facades\Artisan;
        use Orchestra\Testbench\TestCase as BaseTestCase;
        use Vusys\Zefram\Console\Command\ClearCommand as CoreClearCommand;
        use Vusys\Zefram\Console\Command\StatsCommand as CoreStatsCommand;
        use Vusys\Zefram\Laravel\ZeframServiceProvider;
        use Vusys\Zefram\ZeframAware;

        final class ArtisanCommandsTest extends BaseTestCase
        {
            use ZeframAware;

            protected function getPackageProviders($app): array
            {
                return [ZeframServiceProvider::class];
            }

            public function testClearCommandMatchesTheStandaloneCli(): void
            {
                $root = $this->app->basePath();

                ob_start();
                (new CoreClearCommand($root))->run([]);
                $cliOutput = (string) ob_get_clean();

                ob_start();
                Artisan::call('zefram:clear');
                $artisanOutput = (string) ob_get_clean();

                $this->assertNotSame('', $cliOutput);
                $this->assertSame($cliOutput, $artisanOutput);
            }

            public function testStatsJsonCommandMatchesTheStandaloneCli(): void
            {
                $root = $this->app->basePath();

                ob_start();
                (new CoreStatsCommand($root))->run(['--json']);
                $cliOutput = (string) ob_get_clean();

                ob_start();
                Artisan::call('zefram:stats', ['--json' => true]);
                $artisanOutput = (string) ob_get_clean();

                $this->assertNotSame('', $cliOutput);
                $this->assertSame($cliOutput, $artisanOutput);
            }
        }

        PHP_WRAP;
    }

    private function renderBootstrap(): string
    {
        $outerAutoload = addslashes(dirname(__DIR__, 2).'/vendor/autoload.php');

        return <<<PHP
<?php

declare(strict_types=1);

// Generated by Vusys\Zefram\Tests\Support\LaravelProjectRunner. Outer vendor/autoload.php
// supplies PHPUnit, orchestra/testbench, and the real installed Zefram classes; a small
// dedicated autoloader below covers the fixture's own generated namespace.
require '{$outerAutoload}';

spl_autoload_register(static function (string \$class): void {
    \$prefix = 'Zefram\\\\Generated\\\\LaravelFixture\\\\';

    if (! str_starts_with(\$class, \$prefix)) {
        return;
    }

    \$relative = substr(\$class, strlen(\$prefix));
    \$root = __DIR__;

    \$path = str_starts_with(\$relative, 'Tests\\\\')
        ? \$root.'/tests/'.substr(\$relative, strlen('Tests\\\\')).'.php'
        : \$root.'/src/'.str_replace('\\\\', '/', \$relative).'.php';

    if (is_file(\$path)) {
        require \$path;
    }
});

PHP;
    }

    private function renderPhpUnitConfig(): string
    {
        $root = htmlspecialchars($this->root, ENT_XML1);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="{$root}/e2e-bootstrap.php"
         cacheDirectory="{$root}/.phpunit.cache"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="laravel">
            <directory>{$root}/tests</directory>
        </testsuite>
    </testsuites>
    <extensions>
        <bootstrap class="Vusys\\Zefram\\PhpUnit\\ZeframExtension">
            <parameter name="cacheDirectory" value=".zefram"/>
        </bootstrap>
    </extensions>
    <source>
        <include>
            <directory>{$root}/src</directory>
        </include>
    </source>
</phpunit>

XML;
    }
}
