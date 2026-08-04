<?php

declare(strict_types=1);

namespace Vusys\Zefram;

final readonly class Config
{
    /**
     * @param  list<string>  $watchedPaths  glob patterns relative to the project root; any change to a
     *                                      matched file invalidates the entire cache (via the fingerprint)
     * @param  list<string>  $neverSkip  fnmatch patterns matched against "Fully\Qualified\Class::method"
     * @param  bool  $fullRun  unfiltered, non-grouped, non-parallel (see #20's correctness
     *                         guards / #6 / #7): only true is safe to prune stale cache
     *                         state from. Defaults false so constructing an Engine
     *                         directly (as tests do) never accidentally prunes;
     *                         ZeframExtension computes the real value from PHPUnit's
     *                         Configuration + TEST_TOKEN.
     * @param  bool  $paratestWorker  see Vusys\Zefram\Paratest / Engine::isParatestWorker() (#11)
     */
    public function __construct(
        public bool $enabled,
        public string $projectRoot,
        public string $cacheDirectory = '.zefram',
        public array $watchedPaths = [],
        public array $neverSkip = [],
        public ?string $configurationFile = null,
        public bool $fullRun = false,
        public bool $paratestWorker = false,
    ) {}

    public function cachePath(): string
    {
        if (str_starts_with($this->cacheDirectory, '/')) {
            return $this->cacheDirectory;
        }

        return rtrim($this->projectRoot, '/').'/'.$this->cacheDirectory;
    }
}
