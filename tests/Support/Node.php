<?php

declare(strict_types=1);

namespace Vusys\Zefram\Tests\Support;

/**
 * One node in a GraphGenerator graph: its name, its direct dependency names, and optional
 * artificial-expensive-setup timing. Immutable; GraphGenerator::mutate() replaces the node
 * rather than mutating it in place.
 */
final readonly class Node
{
    /**
     * @param  list<string>  $dependencies
     */
    public function __construct(
        public string $name,
        public array $dependencies = [],
        public ?int $expensiveMicroseconds = null,
        public string $extraRunCode = '',
    ) {}

    public function withExtraRunCode(string $extraRunCode): self
    {
        return new self($this->name, $this->dependencies, $this->expensiveMicroseconds, $extraRunCode);
    }
}
