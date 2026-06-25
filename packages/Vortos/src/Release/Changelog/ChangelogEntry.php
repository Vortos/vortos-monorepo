<?php

declare(strict_types=1);

namespace Vortos\Release\Changelog;

final readonly class ChangelogEntry
{
    public function __construct(
        public string $type,
        public ?string $scope,
        public string $description,
        public string $sha,
        public ?string $buildId = null,
    ) {}

    /** @return array{type: string, scope: ?string, description: string, sha: string, build_id: ?string} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'scope' => $this->scope,
            'description' => $this->description,
            'sha' => $this->sha,
            'build_id' => $this->buildId,
        ];
    }
}
