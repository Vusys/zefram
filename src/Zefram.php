<?php

declare(strict_types=1);

namespace Vusys\Zefram;

/**
 * Static bridge between the ZeframAware trait (which has no construction point) and the
 * engine booted by the extension. Inert when the extension isn't registered: the trait
 * becomes a no-op and every test runs normally.
 */
final class Zefram
{
    private static ?Engine $engine = null;

    public static function boot(Engine $engine): void
    {
        self::$engine = $engine;
    }

    public static function reset(): void
    {
        self::$engine = null;
    }

    public static function shouldSkip(string $class, string $method, int|string $dataName): bool
    {
        return self::$engine?->shouldSkip($class, $method, $dataName) ?? false;
    }

    public static function startCapture(string $class, string $method, int|string $dataName): void
    {
        self::$engine?->startCapture($class, $method, $dataName);
    }

    public static function stopCapture(): void
    {
        self::$engine?->stopCapture();
    }
}
