<?php

declare(strict_types=1);

namespace Vortos\Release\Tagging;

final readonly class AppliedTag
{
    public function __construct(
        public string $packageName,
        public string $tagName,
        public string $sha,
        public bool $pushed,
        public bool $signed = false,
    ) {}

    /** @return array{package_name: string, tag_name: string, sha: string, pushed: bool, signed: bool} */
    public function toArray(): array
    {
        return [
            'package_name' => $this->packageName,
            'tag_name' => $this->tagName,
            'sha' => $this->sha,
            'pushed' => $this->pushed,
            'signed' => $this->signed,
        ];
    }

    /** @param array{package_name: string, tag_name: string, sha: string, pushed: bool, signed?: bool} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            packageName: $data['package_name'],
            tagName: $data['tag_name'],
            sha: $data['sha'],
            pushed: $data['pushed'],
            signed: $data['signed'] ?? false,
        );
    }
}
