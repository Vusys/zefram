<?php

declare(strict_types=1);

namespace Vusys\Zefram\PhpUnit;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;

/**
 * Extracts (class, method, dataName) from event-side test objects, matching what the
 * trait sees via $this->name() / $this->dataName(): the string key for named datasets,
 * the integer index for positional ones, '' when there is no provider. Verified
 * identical on both sides against PHPUnit 11.5.
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
