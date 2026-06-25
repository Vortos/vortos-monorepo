<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Emitter;

final readonly class EmittedArtifact
{
    public function __construct(
        public string $relativePath,
        public string $contents,
        public string $description,
        public bool $isExecutable = false,
    ) {
        if ($relativePath === '') {
            throw new \InvalidArgumentException('Artifact path must be non-empty.');
        }

        if (str_starts_with($relativePath, '/')) {
            throw new \InvalidArgumentException(sprintf(
                'Artifact path must be relative, got absolute path "%s".',
                $relativePath,
            ));
        }

        if (str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException(sprintf(
                'Artifact path must not contain "..", got "%s".',
                $relativePath,
            ));
        }
    }
}
