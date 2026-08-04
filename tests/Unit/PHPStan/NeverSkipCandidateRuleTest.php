<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Vusys\Zefram\PHPStan\NeverSkipCandidateRule;

/**
 * @extends RuleTestCase<NeverSkipCandidateRule>
 */
final class NeverSkipCandidateRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NeverSkipCandidateRule;
    }

    public function test_fires_on_now_without_freezing(): void
    {
        $this->analyse([__DIR__.'/Fixtures/NowWithoutFreeze.php'], [
            [
                "now() is non-deterministic and invisible to zefram's coverage heuristic",
                13,
            ],
        ]);
    }

    /**
     * The same call, once a freeze helper precedes it in the same method, must stay silent —
     * this is the case that makes the rule worth building carefully (see its own docblock).
     */
    public function test_stays_silent_on_now_after_freezing(): void
    {
        $this->analyse([__DIR__.'/Fixtures/NowWithFreeze.php'], []);
    }

    /**
     * No Laravel/Larastan symbol anywhere in the fixture — proves the framework-agnostic
     * path is exercised independently of the Laravel-aware one.
     */
    public function test_fires_on_rand_with_no_laravel_present(): void
    {
        // Rector's RandomFunctionRector rewrites rand() -> random_int() repo-wide (see #38)
        // — both are in the rule's own random-function list, so this still exercises the
        // exact same framework-agnostic path the acceptance criterion asks for.
        $this->analyse([__DIR__.'/Fixtures/RandUsage.php'], [
            [
                "random_int() is non-deterministic and invisible to zefram's coverage heuristic",
                17,
            ],
        ]);
    }

    public function test_fires_on_an_unfaked_http_call(): void
    {
        $this->analyse([__DIR__.'/Fixtures/HttpUnfaked.php'], [
            [
                "Http::get() makes a live network call invisible to zefram's coverage heuristic unless faked",
                14,
            ],
        ]);
    }

    public function test_stays_silent_on_a_faked_http_call(): void
    {
        $this->analyse([__DIR__.'/Fixtures/HttpFaked.php'], []);
    }

    public function test_stays_silent_on_a_test_marked_never_skip(): void
    {
        $this->analyse([__DIR__.'/Fixtures/NeverSkipMethod.php'], []);
    }

    /**
     * Zero flags on this package's own real test suite — it's all deterministic
     * (TempProject/FakeDriver, never real time/randomness/network) — proves the rule isn't
     * trigger-happy on ordinary, already-correct code.
     */
    public function test_does_not_flag_this_packages_own_unit_tests(): void
    {
        $files = glob(dirname(__DIR__, 2).'/Unit/*Test.php') ?: [];
        $this->assertNotEmpty($files);

        $this->analyse($files, []);
    }

    /**
     * Same check against #51's real testbench-based Laravel fixture — the harness that
     * actually exercises RefreshDatabase/container resolution/etc. Zero flags expected here
     * too, for the same reason: everything in it is deterministic by construction.
     */
    public function test_does_not_flag_the_testbench_compatibility_fixture(): void
    {
        $file = dirname(__DIR__, 2).'/E2E/Laravel/TestbenchCompatibilityTest.php';
        $this->assertFileExists($file);

        $this->analyse([$file], []);
    }
}
