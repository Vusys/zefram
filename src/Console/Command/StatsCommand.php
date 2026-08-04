<?php

declare(strict_types=1);

namespace Vusys\Zefram\Console\Command;

use Vusys\Zefram\Console\Command;
use Vusys\Zefram\Console\ProjectConfig;
use Vusys\Zefram\Store\TestRecord;

/**
 * `zefram stats` — reads .zefram/<fingerprint>/tests/*.json shards directly, no PHPUnit run
 * involved. Reports, per #20's storage-format section: total tracked tests, how many are
 * eligible to skip next run (have a passed record under the current fingerprint), and
 * aggregate stored duration — the same underlying number #10's warp factor uses, computed
 * standalone here without a live PHPUnit run.
 */
final class StatsCommand implements Command
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    public function run(array $args): int
    {
        $fingerprint = ProjectConfig::currentFingerprint($this->projectRoot);
        $stats = $this->collect($fingerprint);

        echo in_array('--json', $args, true)
            ? self::renderJson($fingerprint, $stats)
            : self::renderText($fingerprint, $stats);

        return 0;
    }

    /**
     * @return array{trackedTests: int, eligibleToSkip: int, recordedDurationSeconds: float}
     */
    private function collect(string $fingerprint): array
    {
        $tracked = 0;
        $eligible = 0;
        $duration = 0.0;

        $shardDirectory = ProjectConfig::cachePath($this->projectRoot) . '/' . $fingerprint . '/tests';

        foreach (glob($shardDirectory . '/*.json') ?: [] as $shardFile) {
            $decoded = json_decode((string) file_get_contents($shardFile), true);

            if (!is_array($decoded) || !isset($decoded['tests']) || !is_array($decoded['tests'])) {
                continue;
            }

            foreach ($decoded['tests'] as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $tracked++;

                if (($record['result'] ?? null) === TestRecord::RESULT_PASSED) {
                    $eligible++;
                }

                $duration += (float) ($record['duration'] ?? 0.0);
            }
        }

        return [
            'trackedTests' => $tracked,
            'eligibleToSkip' => $eligible,
            'recordedDurationSeconds' => $duration,
        ];
    }

    /**
     * @param array{trackedTests: int, eligibleToSkip: int, recordedDurationSeconds: float} $stats
     */
    private static function renderText(string $fingerprint, array $stats): string
    {
        if ($stats['trackedTests'] === 0) {
            return "Zefram stats (fingerprint {$fingerprint}): no tests tracked yet.\n";
        }

        $percent = (int) round($stats['eligibleToSkip'] / $stats['trackedTests'] * 100);

        return sprintf(
            "Zefram stats (fingerprint %s)\n" .
            "  Tracked tests:     %d\n" .
            "  Eligible to skip:  %d (%d%%)\n" .
            "  Recorded duration: %.1fs (sum of stored per-test durations, from each test's last real run)\n",
            $fingerprint,
            $stats['trackedTests'],
            $stats['eligibleToSkip'],
            $percent,
            $stats['recordedDurationSeconds'],
        );
    }

    /**
     * @param array{trackedTests: int, eligibleToSkip: int, recordedDurationSeconds: float} $stats
     */
    private static function renderJson(string $fingerprint, array $stats): string
    {
        return json_encode(
            array_merge(['fingerprint' => $fingerprint], $stats),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ) . "\n";
    }
}
