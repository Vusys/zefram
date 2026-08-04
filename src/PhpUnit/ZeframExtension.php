<?php

declare(strict_types=1);

namespace Vusys\Zefram\PhpUnit;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Vusys\Zefram\Config;
use Vusys\Zefram\Engine;
use Vusys\Zefram\PhpUnit\Subscriber\ExecutionFinishedSubscriber;
use Vusys\Zefram\PhpUnit\Subscriber\TestConsideredRiskySubscriber;
use Vusys\Zefram\PhpUnit\Subscriber\TestErroredSubscriber;
use Vusys\Zefram\PhpUnit\Subscriber\TestFailedSubscriber;
use Vusys\Zefram\PhpUnit\Subscriber\TestMarkedIncompleteSubscriber;
use Vusys\Zefram\PhpUnit\Subscriber\TestPassedSubscriber;
use Vusys\Zefram\PhpUnit\Subscriber\TestSkippedSubscriber;
use Vusys\Zefram\Zefram;

/**
 * Register in phpunit.xml:
 *
 *     <extensions>
 *         <bootstrap class="Vusys\Zefram\PhpUnit\ZeframExtension">
 *             <parameter name="enabled" value="true"/>
 *             <parameter name="cacheDirectory" value=".zefram"/>
 *             <parameter name="watchedPaths" value=".env.testing,config/**,database/migrations/**,resources/views/**/*.blade.php"/>
 *             <parameter name="neverSkip" value="App\Tests\Flaky\*"/>
 *         </bootstrap>
 *     </extensions>
 *
 * Laravel users should watch config/** unconditionally: config files are visible to
 * coverage in uncached runs but invisible once config:cache is active — exactly the
 * CI/production-like runs this package wants to accelerate. Watch Blade sources rather
 * than relying on the compiled artifacts (which view:clear deletes).
 *
 * The ZEFRAM environment variable overrides the enabled parameter (ZEFRAM=0 kill switch).
 * Parameter values always arrive as strings and are cast here.
 */
final class ZeframExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $projectRoot = $parameters->has('projectRoot')
            ? $parameters->get('projectRoot')
            : (string) getcwd();

        $config = new Config(
            enabled: $this->enabled($configuration, $parameters),
            projectRoot: $projectRoot,
            cacheDirectory: $parameters->has('cacheDirectory') ? $parameters->get('cacheDirectory') : '.zefram',
            watchedPaths: $this->list($parameters, 'watchedPaths'),
            neverSkip: $this->list($parameters, 'neverSkip'),
            configurationFile: $this->configurationFile($configuration),
        );

        $engine = Engine::create($config);

        Zefram::boot($engine);

        $facade->registerSubscribers(
            new TestPassedSubscriber($engine),
            new TestFailedSubscriber($engine),
            new TestErroredSubscriber($engine),
            new TestConsideredRiskySubscriber($engine),
            new TestMarkedIncompleteSubscriber($engine),
            new TestSkippedSubscriber($engine),
            new ExecutionFinishedSubscriber($engine),
        );
    }

    private function enabled(Configuration $configuration, ParameterCollection $parameters): bool
    {
        $env = getenv('ZEFRAM');

        if ($env !== false) {
            return !in_array(strtolower($env), ['0', 'false', 'off'], true);
        }

        if ($parameters->has('enabled') && in_array(strtolower($parameters->get('enabled')), ['0', 'false', 'off'], true)) {
            return false;
        }

        // PHPUnit collecting its own coverage and zefram clearing the pcov buffer per test
        // would corrupt each other — and skipping tests would falsify the report anyway.
        if ($configuration->hasCoverageReport()) {
            return false;
        }

        return true;
    }

    private function configurationFile(Configuration $configuration): ?string
    {
        if (method_exists($configuration, 'hasConfigurationFile') && method_exists($configuration, 'configurationFile')) {
            return $configuration->hasConfigurationFile() ? $configuration->configurationFile() : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function list(ParameterCollection $parameters, string $name): array
    {
        if (!$parameters->has($name)) {
            return [];
        }

        $values = array_map(trim(...), explode(',', $parameters->get($name)));

        return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
    }
}
