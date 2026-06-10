<?php

declare(strict_types=1);

namespace Vusys\Zefram\PhpUnit;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;

/**
 * Extracts (class, method, dataName) from event-side test objects, matching what the
 * trait sees via $this->name() / $this->dataName().
 *
 * TODO(spike #6): verify dataSetName() here agrees exactly with TestCase::dataName()
 * for named and positional datasets.
 */
final class TestIdentity
{
    /**
     * @return array{0: string, 1: string, 2: int|string}|null
     */
    public static function fromTest(Test $test): ?array
    {
        if (!$test->isTestMethod()) {
            return null;
        }

        assert($test instanceof TestMethod);

        $dataName = '';

        if ($test->testData()->hasDataFromDataProvider()) {
            $dataName = $test->testData()->dataFromDataProvider()->dataSetName();
        }

        return [$test->className(), $test->methodName(), $dataName];
    }
}
