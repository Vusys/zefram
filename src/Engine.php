<?php

declare(strict_types=1);

namespace Vusys\Zefram;

use ReflectionClass;
use ReflectionMethod;
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

    private int $testsSeen = 0;

    private int $skipped = 0;

    private float $timeSaved = 0.0;

    public function __construct(
        private readonly Config $config,
        private readonly CoverageDriver $driver,
        private readonly Store $store,
        private readonly FileHasher $hasher,
    ) {
    }

    public static function create(Config $config, ?CoverageDriver $driver = null): self
    {
        $driver ??= extension_loaded('pcov') ? new PcovDriver() : new NullDriver();
        $hasher = new FileHasher();
        $fingerprint = Fingerprint::compute($config, $driver->name(), self::VERSION, $hasher);
        $store = new Store($config->cachePath() . '/' . $fingerprint);

        return new self($config, $driver, $store, $hasher);
    }

    public function shouldSkip(string $class, string $method, int|string $dataName): bool
    {
        $this->testsSeen++;

        if (!$this->active() || $this->neverSkips($class, $method)) {
            return false;
        }

        $key = self::testKey($method, $dataName);
        $record = $this->store->get($class, $key);

        if ($record === null || $record->result !== TestRecord::RESULT_PASSED || $record->files === []) {
            return false;
        }

        foreach ($record->files as $relative => $expected) {
            if ($this->hasher->hash($this->absolute($relative)) !== $expected) {
                return false;
            }
        }

        $this->selfSkipped[self::id($class, $key)] = true;
        $this->skipped++;
        $this->timeSaved += $record->duration;

        return true;
    }

    public function startCapture(string $class, string $method, int|string $dataName): void
    {
        if (!$this->active()) {
            return;
        }

        $this->current = [
            'class' => $class,
            'key' => self::testKey($method, $dataName),
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

        $this->pending[self::id($this->current['class'], $this->current['key'])] = [
            'files' => $this->hashProjectFiles($files),
            'duration' => $duration,
        ];

        $this->current = null;
    }

    public function recordPassed(string $class, string $method, int|string $dataName): void
    {
        $key = self::testKey($method, $dataName);
        $id = self::id($class, $key);

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
        $key = self::testKey($method, $dataName);

        $this->takePending(self::id($class, $key));
        $this->store->drop($class, $key);
    }

    public function recordSkipped(string $class, string $method, int|string $dataName): void
    {
        $key = self::testKey($method, $dataName);
        $id = self::id($class, $key);

        $this->takePending($id);

        if (isset($this->selfSkipped[$id])) {
            return; // our own skip: the stored record stays valid
        }

        // Skipped for some other reason: we can't vouch for it, drop it.
        $this->store->drop($class, $key);
    }

    public function finish(): ?string
    {
        $this->store->flush();

        if (!$this->config->enabled || $this->testsSeen === 0) {
            return null;
        }

        if (!$this->driver->isAvailable()) {
            return 'Zefram: no coverage driver available (is pcov installed?), all tests ran';
        }

        return sprintf(
            'Zefram: skipped %d of %d tests (~%s at sublight)',
            $this->skipped,
            $this->testsSeen,
            self::formatSeconds($this->timeSaved),
        );
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
     * @param list<string> $absolutePaths
     *
     * @return array<string, string>
     */
    private function hashProjectFiles(array $absolutePaths): array
    {
        $root = rtrim($this->config->projectRoot, '/') . '/';
        $cachePath = $this->config->cachePath() . '/';
        $files = [];

        foreach ($absolutePaths as $path) {
            if (!str_starts_with($path, $root) || str_starts_with($path, $cachePath)) {
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
            if (fnmatch($pattern, $class . '::' . $method, FNM_NOESCAPE)) {
                return true;
            }
        }

        if (!class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->getAttributes(NeverSkip::class) !== []) {
            return true;
        }

        if ($reflection->hasMethod($method)) {
            $methodReflection = $reflection->getMethod($method);

            if ($methodReflection instanceof ReflectionMethod && $methodReflection->getAttributes(NeverSkip::class) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{files: array<string, string>, duration: float}|null
     */
    private function takePending(string $id): ?array
    {
        // Outcome events can arrive before or after the #[After] hook depending on PHPUnit
        // internals; close the window ourselves if it is still open for this test.
        if (!isset($this->pending[$id]) && $this->current !== null && self::id($this->current['class'], $this->current['key']) === $id) {
            $this->stopCapture();
        }

        $capture = $this->pending[$id] ?? null;
        unset($this->pending[$id]);

        return $capture;
    }

    private function absolute(string $relative): string
    {
        return rtrim($this->config->projectRoot, '/') . '/' . $relative;
    }

    private static function testKey(string $method, int|string $dataName): string
    {
        // Named datasets key on their string key, positional on the integer index —
        // identical between TestCase::dataName() and the event-side dataSetName().
        return $dataName === '' ? $method : $method . '#' . $dataName;
    }

    private static function id(string $class, string $key): string
    {
        return $class . '::' . $key;
    }

    private static function formatSeconds(float $seconds): string
    {
        if ($seconds >= 60) {
            return sprintf('%dm %ds', intdiv((int) $seconds, 60), (int) $seconds % 60);
        }

        return sprintf('%.1fs', $seconds);
    }
}
