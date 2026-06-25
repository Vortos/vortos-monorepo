<?php

declare(strict_types=1);

namespace Vortos\Release\Version;

final readonly class ConventionalCommit
{
    /**
     * @param list<string> $footers
     */
    public function __construct(
        public string $type,
        public ?string $scope,
        public bool $breaking,
        public string $description,
        public string $body,
        public array $footers,
        public string $sha,
    ) {}

    public function toBumpLevel(): BumpLevel
    {
        if ($this->breaking) {
            return BumpLevel::Major;
        }

        return match ($this->type) {
            'feat' => BumpLevel::Minor,
            'fix', 'perf' => BumpLevel::Patch,
            default => BumpLevel::None,
        };
    }
}
