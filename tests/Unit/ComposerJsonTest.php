<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Zefram\Laravel\ZeframServiceProvider;

/**
 * Structural checks on composer.json itself — cheap insurance against exactly the kind of
 * regression a runtime test can't catch: a typo'd or stale FQCN in `extra.laravel.providers`
 * would silently break Laravel package auto-discovery (#52) without ever failing a real
 * PHPUnit assertion, since nothing in this package's own suite resolves that string through
 * Composer's actual discovery mechanism (see LaravelProjectRunner's docblock for why not).
 */
final class ComposerJsonTest extends TestCase
{
    public function test_declares_the_real_service_provider_class_for_laravel_autodiscovery(): void
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
        $this->assertIsArray($decoded);

        $extra = $decoded['extra'] ?? null;
        $this->assertIsArray($extra);

        $laravel = $extra['laravel'] ?? null;
        $this->assertIsArray($laravel);

        $providers = $laravel['providers'] ?? null;
        $this->assertIsArray($providers);
        $this->assertContains(ZeframServiceProvider::class, $providers);
        $this->assertTrue(class_exists(ZeframServiceProvider::class));
    }
}
