<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Store\Store;
use Vusys\Zefram\Store\TestRecord;
use Vusys\Zefram\Tests\Support\TempProject;

final class StoreTest extends TestCase
{
    private TempProject $project;

    protected function setUp(): void
    {
        $this->project = new TempProject;
    }

    protected function tearDown(): void
    {
        // setUp() may throw before $project is assigned — tearDown() still runs, so guard
        // the typed property (see EngineTest for the same pattern).
        if (isset($this->project)) {
            $this->project->destroy();
        }
    }

    public function test_round_trips_records_through_disk(): void
    {
        $record = new TestRecord(['src/A.php' => 'abc123'], 0.5, TestRecord::RESULT_PASSED);

        $store = new Store($this->project->path('.zefram/fp'));
        $store->put('App\\Tests\\AlphaTest', 'testOne', $record);
        $store->flush();

        $reloaded = new Store($this->project->path('.zefram/fp'));
        $found = $reloaded->get('App\\Tests\\AlphaTest', 'testOne');

        $this->assertNotNull($found);
        $this->assertSame($record->toArray(), $found->toArray());
    }

    public function test_returns_null_for_unknown_test(): void
    {
        $store = new Store($this->project->path('.zefram/fp'));

        $this->assertNull($store->get('App\\Tests\\AlphaTest', 'testMissing'));
    }

    public function test_drop_removes_record_and_empty_shard_file(): void
    {
        $store = new Store($this->project->path('.zefram/fp'));
        $store->put('App\\Tests\\AlphaTest', 'testOne', new TestRecord(['src/A.php' => 'x'], 0.1, TestRecord::RESULT_PASSED));
        $store->flush();

        $store->drop('App\\Tests\\AlphaTest', 'testOne');
        $store->flush();

        $reloaded = new Store($this->project->path('.zefram/fp'));

        $this->assertNull($reloaded->get('App\\Tests\\AlphaTest', 'testOne'));
        $this->assertSame([], glob($this->project->path('.zefram/fp/tests/*.json')) ?: []);
    }

    public function test_untouched_shards_are_not_rewritten(): void
    {
        $store = new Store($this->project->path('.zefram/fp'));
        $store->put('App\\Tests\\AlphaTest', 'testOne', new TestRecord(['src/A.php' => 'x'], 0.1, TestRecord::RESULT_PASSED));
        $store->flush();

        [$shardFile] = glob($this->project->path('.zefram/fp/tests/*.json')) ?: [];
        $before = (string) file_get_contents($shardFile);

        $reloaded = new Store($this->project->path('.zefram/fp'));
        $reloaded->get('App\\Tests\\AlphaTest', 'testOne');
        $reloaded->flush();

        $this->assertSame($before, (string) file_get_contents($shardFile));
    }

    public function test_corrupt_shard_is_treated_as_empty(): void
    {
        $store = new Store($this->project->path('.zefram/fp'));
        $store->put('App\\Tests\\AlphaTest', 'testOne', new TestRecord(['src/A.php' => 'x'], 0.1, TestRecord::RESULT_PASSED));
        $store->flush();

        [$shardFile] = glob($this->project->path('.zefram/fp/tests/*.json')) ?: [];
        file_put_contents($shardFile, '{not json');

        $reloaded = new Store($this->project->path('.zefram/fp'));

        $this->assertNull($reloaded->get('App\\Tests\\AlphaTest', 'testOne'));
    }

    public function test_prune_siblings_deletes_every_fingerprint_directory_except_the_current_one(): void
    {
        $cacheRoot = $this->project->path('.zefram');
        $this->populateShard($cacheRoot.'/current');
        $this->populateShard($cacheRoot.'/stale-one');
        $this->populateShard($cacheRoot.'/stale-two');

        Store::pruneSiblings($cacheRoot, 'current');

        $this->assertDirectoryExists($cacheRoot.'/current');
        $this->assertDirectoryDoesNotExist($cacheRoot.'/stale-one');
        $this->assertDirectoryDoesNotExist($cacheRoot.'/stale-two');
    }

    public function test_prune_siblings_is_a_no_op_on_a_missing_cache_root(): void
    {
        // must not throw
        Store::pruneSiblings($this->project->path('.zefram'), 'current');

        $this->assertDirectoryDoesNotExist($this->project->path('.zefram'));
    }

    public function test_prune_siblings_leaves_everything_when_only_the_current_fingerprint_exists(): void
    {
        $cacheRoot = $this->project->path('.zefram');
        $this->populateShard($cacheRoot.'/current');

        Store::pruneSiblings($cacheRoot, 'current');

        $this->assertDirectoryExists($cacheRoot.'/current');
    }

    public function test_prune_stale_records_drops_ids_not_in_the_observed_set(): void
    {
        $store = new Store($this->project->path('.zefram/fp'));
        $store->put('App\\Tests\\AlphaTest', 'testOne', new TestRecord(['src/A.php' => 'x'], 0.1, TestRecord::RESULT_PASSED));
        $store->put('App\\Tests\\AlphaTest', 'testTwo', new TestRecord(['src/A.php' => 'x'], 0.1, TestRecord::RESULT_PASSED));
        $store->flush();

        $store->pruneStaleRecords(['App\\Tests\\AlphaTest::testOne' => true]);
        $store->flush();

        $reloaded = new Store($this->project->path('.zefram/fp'));
        $this->assertNotNull($reloaded->get('App\\Tests\\AlphaTest', 'testOne'));
        $this->assertNull($reloaded->get('App\\Tests\\AlphaTest', 'testTwo'), 'not in the observed set: must be dropped');
    }

    public function test_prune_stale_records_scans_shards_never_loaded_this_run(): void
    {
        // simulates a whole test class deleted from source: its shard sits on disk but no
        // get()/put() this run ever touches it, since no test of it ran or was skipped
        $original = new Store($this->project->path('.zefram/fp'));
        $original->put('App\\Tests\\VanishedTest', 'testGone', new TestRecord(['src/A.php' => 'x'], 0.1, TestRecord::RESULT_PASSED));
        $original->flush();

        $freshRun = new Store($this->project->path('.zefram/fp'));
        $freshRun->pruneStaleRecords([]); // nothing observed this pass
        $freshRun->flush();

        $reloaded = new Store($this->project->path('.zefram/fp'));
        $this->assertNull($reloaded->get('App\\Tests\\VanishedTest', 'testGone'));
        $this->assertSame([], glob($this->project->path('.zefram/fp/tests/*.json')) ?: [], 'the now-empty shard file must be removed too');
    }

    public function test_prune_stale_records_keeps_everything_when_all_ids_are_observed(): void
    {
        $store = new Store($this->project->path('.zefram/fp'));
        $store->put('App\\Tests\\AlphaTest', 'testOne', new TestRecord(['src/A.php' => 'x'], 0.1, TestRecord::RESULT_PASSED));
        $store->flush();

        $store->pruneStaleRecords(['App\\Tests\\AlphaTest::testOne' => true]);
        $store->flush();

        $reloaded = new Store($this->project->path('.zefram/fp'));
        $this->assertNotNull($reloaded->get('App\\Tests\\AlphaTest', 'testOne'));
    }

    private function populateShard(string $fingerprintDirectory): void
    {
        $store = new Store($fingerprintDirectory);
        $store->put('App\\Tests\\AlphaTest', 'testOne', new TestRecord(['src/A.php' => 'x'], 0.1, TestRecord::RESULT_PASSED));
        $store->flush();
    }
}
