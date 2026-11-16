<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Vusys\Zefram\PHPStan\UnguardedTearDownRule;

/**
 * @extends RuleTestCase<UnguardedTearDownRule>
 */
final class UnguardedTearDownRuleTest extends RuleTestCase
{
    private const MESSAGE = 'tearDown() uses the framework before parent::tearDown() without checking $this->zeframSkipped(). A test zefram skipped never booted the app, so this throws.';

    private const TIP = 'Return early with parent::tearDown() when $this->zeframSkipped() is true.';

    protected function getRule(): Rule
    {
        return new UnguardedTearDownRule;
    }

    public function test_fires_on_a_facade_call_before_parent_teardown(): void
    {
        $this->analyse([__DIR__.'/Fixtures/TearDownUnguarded.php'], [[self::MESSAGE, 17, self::TIP]]);
    }

    public function test_stays_silent_when_guarded_by_zefram_skipped(): void
    {
        $this->analyse([__DIR__.'/Fixtures/TearDownGuarded.php'], []);
    }

    public function test_stays_silent_on_framework_use_after_parent_teardown(): void
    {
        $this->analyse([__DIR__.'/Fixtures/TearDownAfterParent.php'], []);
    }

    public function test_fires_on_this_app_when_the_trait_is_on_a_parent_class(): void
    {
        $this->analyse([__DIR__.'/Fixtures/TearDownInheritedTrait.php', __DIR__.'/Fixtures/ZeframBase.php'], [[self::MESSAGE, 11, self::TIP]]);
    }

    public function test_ignores_tests_without_zefram(): void
    {
        $this->analyse([__DIR__.'/Fixtures/TearDownWithoutZefram.php'], []);
    }
}
