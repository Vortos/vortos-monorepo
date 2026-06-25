<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final readonly class SplitPackage
{
    public function __construct(
        public string $localPath,
        public string $splitRepository,
    ) {
        if ($localPath === '') {
            throw new \InvalidArgumentException('Split package local path must be non-empty.');
        }

        if ($splitRepository === '') {
            throw new \InvalidArgumentException('Split package repository name must be non-empty.');
        }
    }

    /** @return array{local_path: string, split_repository: string} */
    public function toArray(): array
    {
        return [
            'local_path' => $this->localPath,
            'split_repository' => $this->splitRepository,
        ];
    }
}
