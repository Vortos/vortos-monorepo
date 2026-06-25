<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

use Vortos\Pipeline\Exception\UnpinnedActionException;

final readonly class PinnedAction
{
    public function __construct(
        public string $owner,
        public string $repo,
        public string $sha,
        public string $versionComment,
    ) {
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw UnpinnedActionException::forSha($sha);
        }

        if ($owner === '' || $repo === '') {
            throw new \InvalidArgumentException('Action owner and repo must be non-empty.');
        }
    }

    public function toUsesString(): string
    {
        return sprintf('%s/%s@%s', $this->owner, $this->repo, $this->sha);
    }

    public function toCommentedString(): string
    {
        return sprintf('%s/%s@%s # %s', $this->owner, $this->repo, $this->sha, $this->versionComment);
    }

    /** @return array{owner: string, repo: string, sha: string, version: string} */
    public function toArray(): array
    {
        return [
            'owner' => $this->owner,
            'repo' => $this->repo,
            'sha' => $this->sha,
            'version' => $this->versionComment,
        ];
    }
}
