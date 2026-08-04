<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Console\Command\StatsCommand;
use Vusys\Zefram\Console\ProjectConfig;
use Vusys\Zefram\Store\Store;
use Vusys\Zefram\Store\TestRecord;
use Vusys\Zefram\Tests\Support\TempProject;

final class StatsCommandTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject();
        $this->project->write('composer.lock', '{"packages": []}');
    }

    protected function tearDown(): void
    {
        if (isset($this->project)) {
            $this->project->destroy();
        }
    }

    public function testReportsZeroTrackedTestsWhenNoCacheExistsYet(): void
    {
        $output = $this->runStats();

        $this->assertStringContainsString('no tests tracked yet', $output);
    }

    public function testReportsZeroTrackedTestsAsJsonWhenNoCacheExistsYet(): void
    {
        $decoded = json_decode($this->runStats(['--json']), true);

        $this->assertSame(0, $decoded['trackedTests']);
        $this->assertSame(0, $decoded['eligibleToSkip']);
        $this->assertSame(0.0, $decoded['recordedDurationSeconds']);
        $this->assertArrayHasKey('fingerprint', $decoded);
    }

    public function testReportsRealStoredRecordsUnderTheCurrentFingerprint(): void
    {
        $this->populateCurrentFingerprintShards();

        $output = $this->runStats();

        $this->assertStringContainsString('Tracked tests:     3', $output);
        $this->assertStringContainsString('Eligible to skip:  3 (100%)', $output);
        $this->assertStringContainsString('2.0s', $output);
    }

    public function testJsonOutputMatchesTextOutput(): void
    {
        $this->populateCurrentFingerprintShards();

        $decoded = json_decode($this->runStats(['--json']), true);

        $this->assertSame(3, $decoded['trackedTests']);
        $this->assertSame(3, $decoded['eligibleToSkip']);
        $this->assertEqualsWithDelta(2.02, $decoded['recordedDurationSeconds'], 0.001);
    }

    public function testIgnoresRecordsUnderADifferentStaleFingerprint(): void
    {
        // simulate a stale fingerprint directory that a real config change left behind —
        // stats must only ever read the *current* fingerprint's shards
        $store = new Store($this->project->path('.zefram/some-other-stale-fingerprint'));
        $store->put('App\\Tests\\GammaTest', 'testFour', new TestRecord(['src/G.php' => 'z'], 9.9, TestRecord::RESULT_PASSED));
        $store->flush();

        $output = $this->runStats();

        $this->assertStringContainsString('no tests tracked yet', $output);
    }

    private function populateCurrentFingerprintShards(): void
    {
        $fingerprint = ProjectConfig::currentFingerprint($this->project->root);
        $store = new Store($this->project->path('.zefram/' . $fingerprint));
        $store->put('App\\Tests\\AlphaTest', 'testOne', new TestRecord(['src/A.php' => 'x'], 0.42, TestRecord::RESULT_PASSED));
        $store->put('App\\Tests\\AlphaTest', 'testTwo', new TestRecord(['src/A.php' => 'x'], 1.5, TestRecord::RESULT_PASSED));
        $store->put('App\\Tests\\BetaTest', 'testThree', new TestRecord(['src/B.php' => 'y'], 0.1, TestRecord::RESULT_PASSED));
        $store->flush();
    }

    /**
     * @param list<string> $args
     */
    private function runStats(array $args = []): string
    {
        ob_start();
        (new StatsCommand($this->project->root))->run($args);

        return (string) ob_get_clean();
    }
}
