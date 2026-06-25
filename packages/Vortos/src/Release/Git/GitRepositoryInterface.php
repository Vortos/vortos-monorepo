<?php

declare(strict_types=1);

namespace Vortos\Release\Git;

interface GitRepositoryInterface
{
    public function currentSha(): string;

    public function isClean(): bool;

    public function currentBranch(): string;

    /** @return list<string> */
    public function tagsMatching(string $prefix): array;

    /** @return list<RawCommit> */
    public function commitsBetween(?string $from, string $to): array;

    public function treeShaForPath(string $path): string;

    public function createAnnotatedTag(string $name, string $sha, string $message, bool $sign = false): void;

    public function deleteLocalTag(string $name): void;

    public function pushTag(string $remote, string $name): void;

    public function deleteRemoteTag(string $remote, string $name): void;

    public function tagExists(string $name): bool;

    public function tagSha(string $name): ?string;

    public function verifyTagSignature(string $name): bool;
}
