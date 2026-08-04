<?php

declare(strict_types=1);

namespace Vusys\Zefram\Attribute;

use Attribute;

/**
 * Marks a test class or method that Zefram must always run, never skip — for tests
 * with dependencies coverage can't see (time, randomness, network, external state).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class NeverSkip {}
