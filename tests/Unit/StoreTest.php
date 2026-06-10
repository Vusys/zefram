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
        $this->project = new TempProject();
    }

    protected function tearDown(): void
    {
        $this->project->destroy();
    }

    public function testRoundTripsRecordsThroughDisk(): void
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

    public function testReturnsNullForUnknownTest(): void
    {
        $store = new Store($this->project->path('.zefram/fp'));

        $this->assertNull($store->get('App\\Tests\\AlphaTest', 'testMissing'));
    }

    public function testDropRemovesRecordAndEmptyShardFile(): void
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

    public function testUntouchedShardsAreNotRewritten(): void
    {
        $store = new Store($this->project->path('.zefram/fp'));
        $store->put('App\\Tests\\AlphaTest', 'testOne', new TestRecord(['src/A.php' => 'x'], 0.1, TestRecord::RESULT_PASSED));
        $store->flush();

        [$shardFile] = glob($this->project->path('.zefram/fp/tests/*.json'));
        $before = (string) file_get_contents($shardFile);

        $reloaded = new Store($this->project->path('.zefram/fp'));
        $reloaded->get('App\\Tests\\AlphaTest', 'testOne');
        $reloaded->flush();

        $this->assertSame($before, (string) file_get_contents($shardFile));
    }

    public function testCorruptShardIsTreatedAsEmpty(): void
    {
        $store = new Store($this->project->path('.zefram/fp'));
        $store->put('App\\Tests\\AlphaTest', 'testOne', new TestRecord(['src/A.php' => 'x'], 0.1, TestRecord::RESULT_PASSED));
        $store->flush();

        [$shardFile] = glob($this->project->path('.zefram/fp/tests/*.json'));
        file_put_contents($shardFile, '{not json');

        $reloaded = new Store($this->project->path('.zefram/fp'));

        $this->assertNull($reloaded->get('App\\Tests\\AlphaTest', 'testOne'));
    }
}
