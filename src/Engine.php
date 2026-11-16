<?php

declare(strict_types=1);

namespace Vusys\Zefram;

use Closure;
use ReflectionClass;
use Vusys\Zefram\Attribute\NeverSkip;
use Vusys\Zefram\Coverage\CoverageDriver;
use Vusys\Zefram\Coverage\NullDriver;
use Vusys\Zefram\Coverage\PcovDriver;
use Vusys\Zefram\Hash\FileHasher;
use Vusys\Zefram\Store\Store;
use Vusys\Zefram\Store\TestRecord;

final class Engine
{
    public const VERSION = '0.1.0';

    /**
     * Base classes of browser tests that drive a separately served copy of the app (#80).
     * The code serving their requests runs in another process, which pcov here never sees,
     * so their record would be incomplete: they are never skipped.
     */
    public const OUT_OF_PROCESS_BROWSER_BASES = [
        'Vusys\Tetryon\PHPUnit\BrowserTestCase',
        'Vusys\Tetryon\Laravel\BrowserTestCase',
        'Laravel\Dusk\TestCase',
        'Symfony\Component\Panther\PantherTestCase',
    ];

    /** @var array{class: string, key: string, startedAt: float}|null */
    private ?array $current = null;

    /**
     * Captured-but-not-yet-judged tests: id => capture. Outcome events drain this.
     *
     * @var array<string, array{files: array<string, string>, duration: float}>
     */
    private array $pending = [];

    /** @var array<string, true> */
    private array $selfSkipped = [];

    /**
     * Every test id actually seen (ran or skipped) this pass — shouldSkip() is the trait's
     * universal entry point, called exactly once per test regardless of outcome, so it is
     * where #7's stale-record pruning gets its "what's still real" set.
     *
     * @var array<string, true>
     */
    private array $observed = [];

    private int $testsSeen = 0;

    private int $skipped = 0;

    private float $timeSaved = 0.0;

    /**
     * Sum of stopCapture()'s real measured duration for every test that actually ran this
     * pass (any outcome — pass, fail, error, risky, incomplete, foreign-skip-after-running),
     * as opposed to $timeSaved, which is stored duration for tests we skipped. Together
     * these are the numerator of the "warp factor" (#10): what this pass would have cost at
     * sublight, versus $elapsed, what it actually cost.
     */
    private float $ranDuration = 0.0;

    private readonly Closure $clock;

    private readonly int $startedAt;

    public function __construct(
        private readonly Config $config,
        private readonly CoverageDriver $driver,
        private readonly Store $store,
        private readonly FileHasher $hasher,
        private readonly string $fingerprint = '',
        ?Closure $clock = null,
    ) {
        // injectable for deterministic warp-factor tests; real usage always gets hrtime()
        $this->clock = $clock ?? static fn (): int => hrtime(true);
        $this->startedAt = ($this->clock)();
    }

    public static function create(Config $config, ?CoverageDriver $driver = null): self
    {
        $driver ??= extension_loaded('pcov') ? new PcovDriver : new NullDriver;
        $hasher = new FileHasher;
        $fingerprint = Fingerprint::compute($config, $driver->name(), self::VERSION, $hasher);
        $store = new Store($config->cachePath().'/'.$fingerprint);

        return new self($config, $driver, $store, $hasher, $fingerprint);
    }

    public function shouldSkip(string $class, string $method, int|string $dataName): bool
    {
        $this->testsSeen++;

        $key = $this->testKey($method, $dataName);
        $this->observed[$this->id($class, $key)] = true;

        if (! $this->active() || $this->neverSkips($class, $method)) {
            return false;
        }

        $record = $this->store->get($class, $key);

        if (! $record instanceof TestRecord || $record->result !== TestRecord::RESULT_PASSED || $record->files === []) {
            return false;
        }

        foreach ($record->files as $relative => $expected) {
            if ($this->hasher->hash($this->absolute($relative)) !== $expected) {
                return false;
            }
        }

        $this->selfSkipped[$this->id($class, $key)] = true;
        $this->skipped++;
        $this->timeSaved += $record->duration;

        return true;
    }

    public function startCapture(string $class, string $method, int|string $dataName): void
    {
        if (! $this->active()) {
            return;
        }

        $this->current = [
            'class' => $class,
            'key' => $this->testKey($method, $dataName),
            'startedAt' => hrtime(true),
        ];

        $this->driver->start();
    }

    public function stopCapture(): void
    {
        if ($this->current === null) {
            return;
        }

        $files = $this->driver->stop();
        $duration = (hrtime(true) - $this->current['startedAt']) / 1_000_000_000;

        $this->pending[$this->id($this->current['class'], $this->current['key'])] = [
            'files' => $this->hashProjectFiles($files),
            'duration' => $duration,
        ];
        $this->ranDuration += $duration;

        $this->current = null;
    }

    public function recordPassed(string $class, string $method, int|string $dataName): void
    {
        $key = $this->testKey($method, $dataName);
        $id = $this->id($class, $key);

        $capture = $this->takePending($id);

        if ($capture === null || $capture['files'] === []) {
            return;
        }

        $this->store->put($class, $key, new TestRecord(
            files: $capture['files'],
            duration: $capture['duration'],
            result: TestRecord::RESULT_PASSED,
        ));
    }

    /**
     * Failed, errored, risky, incomplete: drop any stored record so the test always reruns
     * until it passes again.
     */
    public function recordNotPassed(string $class, string $method, int|string $dataName): void
    {
        $key = $this->testKey($method, $dataName);

        $this->takePending($this->id($class, $key));
        $this->store->drop($class, $key);
    }

    public function recordSkipped(string $class, string $method, int|string $dataName): void
    {
        $key = $this->testKey($method, $dataName);
        $id = $this->id($class, $key);

        $this->takePending($id);

        if (isset($this->selfSkipped[$id])) {
            return; // our own skip: the stored record stays valid
        }

        // Skipped for some other reason: we can't vouch for it, drop it.
        $this->store->drop($class, $key);
    }

    public function finish(): ?string
    {
        $fullRun = $this->active() && $this->isFullRun();

        if ($fullRun) {
            // must run before flush() so the drops persist (#7)
            $this->store->pruneStaleRecords($this->observed);
        }

        $this->store->flush();

        if ($fullRun) {
            Store::pruneSiblings($this->config->cachePath(), $this->fingerprint);
        }

        if (! $this->config->enabled || $this->testsSeen === 0) {
            return null;
        }

        if (! $this->driver->isAvailable()) {
            return 'Zefram: no coverage driver available (is pcov installed?), all tests ran';
        }

        return sprintf(
            'Zefram: skipped %d of %d tests (~%s at sublight, warp factor %.1f)',
            $this->skipped,
            $this->testsSeen,
            $this->formatSeconds($this->timeSaved),
            $this->warpFactor(),
        );
    }

    /**
     * (sum of stored duration for every skipped test, i.e. what it cost the last time it
     * actually ran, plus the real measured duration of every test that did run this pass)
     * divided by (the actual measured wall time of this pass). ~1.0 with nothing skipped;
     * heavy skipping pushes it up. A real computed number, not illustrative text.
     */
    public function warpFactor(): float
    {
        $elapsed = (($this->clock)() - $this->startedAt) / 1_000_000_000;

        if ($elapsed <= 0.0) {
            return 1.0;
        }

        return ($this->ranDuration + $this->timeSaved) / $elapsed;
    }

    /**
     * Unfiltered, non-grouped, non-parallel — see #20's correctness guards. Only safe
     * condition to prune stale cache state under (#6 fingerprint directories, #7 stale
     * per-test records): a partial or parallel run must never delete state another process
     * or a later un-filtered run might still need. Computed once by ZeframExtension from
     * PHPUnit's Configuration + TEST_TOKEN; defaults false on a directly-constructed Engine.
     * Also unconditionally false under paratest regardless of what \$config->fullRun says —
     * a structural guarantee (#11) rather than trusting every caller to have already
     * factored isParatestWorker() into fullRun themselves.
     */
    public function isFullRun(): bool
    {
        return $this->config->fullRun && ! $this->config->paratestWorker;
    }

    /**
     * Paratest sets TEST_TOKEN in every worker process it spawns (see Vusys\Zefram\Paratest,
     * #11). A worker only ever sees a partial view of the suite (paratest's default
     * WrapperRunner distributes work per test class), which #12's per-worker summary
     * suppression also keys off.
     */
    public function isParatestWorker(): bool
    {
        return $this->config->paratestWorker;
    }

    private function active(): bool
    {
        return $this->config->enabled && $this->driver->isAvailable();
    }

    /**
     * Filter the captured absolute paths to project files and pin their current hashes.
     * Vendor is excluded (composer.lock covers it via the fingerprint), as is the cache
     * directory and anything outside the project root.
     *
     * @param  list<string>  $absolutePaths
     * @return array<string, string>
     */
    private function hashProjectFiles(array $absolutePaths): array
    {
        $root = rtrim($this->config->projectRoot, '/').'/';
        $cachePath = $this->config->cachePath().'/';
        $files = [];

        foreach ($absolutePaths as $path) {
            if (! str_starts_with($path, $root)) {
                continue;
            }
            if (str_starts_with($path, $cachePath)) {
                continue;
            }
            $relative = substr($path, strlen($root));

            if (str_starts_with($relative, 'vendor/')) {
                continue;
            }

            $hash = $this->hasher->hash($path);

            if ($hash !== null) {
                $files[$relative] = $hash;
            }
        }

        ksort($files);

        return $files;
    }

    private function neverSkips(string $class, string $method): bool
    {
        foreach ($this->config->neverSkip as $pattern) {
            if (fnmatch($pattern, $class.'::'.$method, FNM_NOESCAPE)) {
                return true;
            }
        }

        if (! class_exists($class)) {
            return false;
        }

        foreach (self::OUT_OF_PROCESS_BROWSER_BASES as $base) {
            if (is_a($class, $base, true)) {
                return true;
            }
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->getAttributes(NeverSkip::class) !== []) {
            return true;
        }

        return $reflection->hasMethod($method) && $reflection->getMethod($method)->getAttributes(NeverSkip::class) !== [];
    }

    /**
     * @return array{files: array<string, string>, duration: float}|null
     */
    private function takePending(string $id): ?array
    {
        // Outcome events can arrive before or after the #[After] hook depending on PHPUnit
        // internals; close the window ourselves if it is still open for this test.
        if (! isset($this->pending[$id]) && $this->current !== null && $this->id($this->current['class'], $this->current['key']) === $id) {
            $this->stopCapture();
        }

        $capture = $this->pending[$id] ?? null;
        unset($this->pending[$id]);

        return $capture;
    }

    private function absolute(string $relative): string
    {
        return rtrim($this->config->projectRoot, '/').'/'.$relative;
    }

    private function testKey(string $method, int|string $dataName): string
    {
        // Named datasets key on their string key, positional on the integer index —
        // identical between TestCase::dataName() and the event-side dataSetName().
        return $dataName === '' ? $method : $method.'#'.$dataName;
    }

    private function id(string $class, string $key): string
    {
        return $class.'::'.$key;
    }

    private function formatSeconds(float $seconds): string
    {
        if ($seconds >= 60) {
            return sprintf('%dm %ds', intdiv((int) $seconds, 60), (int) $seconds % 60);
        }

        return sprintf('%.1fs', $seconds);
    }
}
