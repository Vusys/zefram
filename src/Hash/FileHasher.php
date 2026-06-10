<?php

declare(strict_types=1);

namespace Vusys\Zefram\Hash;

/**
 * Hashes file contents with xxh128, memoized per process so a file is read at most
 * once per run no matter how many tests touched it.
 */
final class FileHasher
{
    /** @var array<string, string|null> */
    private array $hashes = [];

    public function hash(string $absolutePath): ?string
    {
        if (array_key_exists($absolutePath, $this->hashes)) {
            return $this->hashes[$absolutePath];
        }

        return $this->hashes[$absolutePath] = $this->compute($absolutePath);
    }

    private function compute(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $hash = @hash_file('xxh128', $path);

        return $hash === false ? null : $hash;
    }
}
