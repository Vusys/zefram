<?php

declare(strict_types=1);

namespace Vusys\Zefram;

final readonly class Config
{
    /**
     * @param list<string> $watchedPaths glob patterns relative to the project root; any change to a
     *                                   matched file invalidates the entire cache (via the fingerprint)
     * @param list<string> $neverSkip    fnmatch patterns matched against "Fully\Qualified\Class::method"
     */
    public function __construct(
        public bool $enabled,
        public string $projectRoot,
        public string $cacheDirectory = '.zefram',
        public array $watchedPaths = [],
        public array $neverSkip = [],
        public ?string $configurationFile = null,
    ) {
    }

    public function cachePath(): string
    {
        if (str_starts_with($this->cacheDirectory, '/')) {
            return $this->cacheDirectory;
        }

        return rtrim($this->projectRoot, '/') . '/' . $this->cacheDirectory;
    }
}
