<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

use SimpleXMLElement;

/**
 * Parses a PHPUnit --log-junit report structurally, so E2E tests assert on which test ids
 * ran vs. skipped rather than scraping stdout.
 */
final class JUnitReport
{
    /**
     * @return list<TestOutcome>
     */
    public static function parse(string $path): array
    {
        $xml = @simplexml_load_file($path);

        if ($xml === false) {
            return [];
        }

        $outcomes = [];
        self::walk($xml, $outcomes);

        return $outcomes;
    }

    /**
     * @param list<TestOutcome> $outcomes
     */
    private static function walk(SimpleXMLElement $node, array &$outcomes): void
    {
        foreach ($node->testsuite as $suite) {
            self::walk($suite, $outcomes);
        }

        foreach ($node->testcase as $case) {
            $class = (string) $case['class'];
            $method = (string) $case['name'];

            $status = TestOutcome::PASSED;

            if (isset($case->skipped)) {
                $status = TestOutcome::SKIPPED;
            } elseif (isset($case->error)) {
                $status = TestOutcome::ERRORED;
            } elseif (isset($case->failure)) {
                $status = TestOutcome::FAILED;
            }

            $outcomes[] = new TestOutcome($class, $method, $status);
        }
    }
}
