<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Emitter;

/**
 * @implements \IteratorAggregate<int, EmittedArtifact>
 */
final readonly class EmittedArtifactSet implements \IteratorAggregate, \Countable
{
    /** @var list<EmittedArtifact> */
    public array $artifacts;

    /** @param list<EmittedArtifact> $artifacts */
    public function __construct(array $artifacts)
    {
        $this->artifacts = $artifacts;
    }

    public function count(): int
    {
        return \count($this->artifacts);
    }

    public function byPath(string $relativePath): ?EmittedArtifact
    {
        foreach ($this->artifacts as $artifact) {
            if ($artifact->relativePath === $relativePath) {
                return $artifact;
            }
        }

        return null;
    }

    /** @return \ArrayIterator<int, EmittedArtifact> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->artifacts);
    }

    public function isEmpty(): bool
    {
        return $this->artifacts === [];
    }
}
