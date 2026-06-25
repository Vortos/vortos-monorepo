<?php

declare(strict_types=1);

namespace Vortos\Release\Service;

use Vortos\Release\Audit\ReleaseAuditEmitterInterface;
use Vortos\Release\Audit\ReleaseAuditEvent;
use Vortos\Release\Git\GitRepositoryInterface;
use Vortos\Release\Plan\PackageTagPlan;
use Vortos\Release\Plan\ReleasePlan;
use Vortos\Release\Tagging\AppliedTag;
use Vortos\Release\Tagging\TaggingStatus;
use Vortos\Release\Tagging\TaggingTransaction;
use Vortos\Release\Tagging\TaggingTransactionStoreInterface;

final class CoordinatedTagger
{
    public function __construct(
        private readonly GitRepositoryInterface $git,
        private readonly TaggingTransactionStoreInterface $store,
        private readonly ReleaseAuditEmitterInterface $auditEmitter,
    ) {}

    public function apply(ReleasePlan $plan, bool $push, bool $sign = false): TaggingTransaction
    {
        $packagesWithChanges = $plan->packagesWithChanges();

        if ($packagesWithChanges === []) {
            throw new ReleaseException('No packages have releasable changes.');
        }

        $this->guardDivergentTags($packagesWithChanges);

        $currentSha = $this->git->currentSha();

        $tx = new TaggingTransaction(
            id: $plan->txId,
            createdAt: $plan->createdAt,
            tags: [],
            status: TaggingStatus::Planned,
        );

        try {
            foreach ($packagesWithChanges as $pkg) {
                $tagName = $pkg->tagName();

                if ($this->git->tagExists($tagName)) {
                    $existingSha = $this->git->tagSha($tagName);
                    if ($existingSha === $currentSha) {
                        $tx->addTag(new AppliedTag($pkg->packageName, $tagName, $currentSha, false, $sign));
                        continue;
                    }
                }

                $message = sprintf(
                    'Release %s for %s',
                    $tagName,
                    $pkg->packageName,
                );

                $this->git->createAnnotatedTag($tagName, $currentSha, $message, $sign);
                $tx->addTag(new AppliedTag($pkg->packageName, $tagName, $currentSha, false, $sign));

                $this->auditEmitter->emit(new ReleaseAuditEvent(
                    action: 'tag.created',
                    transactionId: $plan->txId,
                    tagName: $tagName,
                    packageName: $pkg->packageName,
                    sha: $currentSha,
                    occurredAt: new \DateTimeImmutable(),
                ));
            }

            if ($push) {
                $this->pushTags($tx, $packagesWithChanges);
            }

            $tx->markComplete();
        } catch (\Throwable $e) {
            $tx->markPartial();
            $this->store->save($tx);

            throw new ReleaseException(
                sprintf('Release failed mid-apply: %s. Transaction %s recorded as partial. Use --undo %s to clean up.', $e->getMessage(), $tx->id, $tx->id),
                previous: $e,
            );
        }

        $this->store->save($tx);

        return $tx;
    }

    public function undo(string $txId): TaggingTransaction
    {
        $tx = $this->store->load($txId);

        if ($tx === null) {
            throw new ReleaseException(sprintf('Tagging transaction "%s" not found.', $txId));
        }

        if ($tx->status === TaggingStatus::Undone) {
            throw new ReleaseException(sprintf('Transaction "%s" is already undone.', $txId));
        }

        foreach (array_reverse($tx->tags) as $tag) {
            if ($tag->signed && $this->git->tagExists($tag->tagName) && !$this->git->verifyTagSignature($tag->tagName)) {
                throw new ReleaseException(sprintf(
                    'Tag "%s" has an invalid or missing signature — refusing to undo. '
                    . 'Verify the tag was not tampered with before retrying.',
                    $tag->tagName,
                ));
            }

            if ($tag->pushed) {
                try {
                    $this->git->deleteRemoteTag('origin', $tag->tagName);
                } catch (\Throwable) {
                    // best-effort remote cleanup
                }
            }

            try {
                $this->git->deleteLocalTag($tag->tagName);
            } catch (\Throwable) {
                // tag may not exist locally
            }

            $this->auditEmitter->emit(new ReleaseAuditEvent(
                action: 'tag.undone',
                transactionId: $tx->id,
                tagName: $tag->tagName,
                packageName: $tag->packageName,
                sha: $tag->sha,
                occurredAt: new \DateTimeImmutable(),
            ));
        }

        $tx->markUndone();
        $this->store->save($tx);

        return $tx;
    }

    /** @param list<PackageTagPlan> $packages */
    private function guardDivergentTags(array $packages): void
    {
        $currentSha = $this->git->currentSha();

        foreach ($packages as $pkg) {
            $tagName = $pkg->tagName();

            if (!$this->git->tagExists($tagName)) {
                continue;
            }

            $existingSha = $this->git->tagSha($tagName);

            if ($existingSha !== $currentSha) {
                throw new ReleaseException(sprintf(
                    'Tag "%s" already exists pointing to %s, but HEAD is %s. '
                    . 'Refusing to overwrite a divergent tag. Delete the existing tag first or use --undo.',
                    $tagName,
                    $existingSha ?? 'unknown',
                    $currentSha,
                ));
            }
        }
    }

    /** @param list<PackageTagPlan> $packages */
    private function pushTags(TaggingTransaction $tx, array $packages): void
    {
        foreach ($tx->tags as $i => $tag) {
            $pkg = $this->findPackage($packages, $tag->packageName);

            if ($pkg === null) {
                continue;
            }

            $this->git->pushTag($pkg->remote, $tag->tagName);
            $tx->tags[$i] = new AppliedTag($tag->packageName, $tag->tagName, $tag->sha, true, $tag->signed);

            $this->auditEmitter->emit(new ReleaseAuditEvent(
                action: 'tag.pushed',
                transactionId: $tx->id,
                tagName: $tag->tagName,
                packageName: $tag->packageName,
                sha: $tag->sha,
                occurredAt: new \DateTimeImmutable(),
            ));
        }
    }

    /** @param list<PackageTagPlan> $packages */
    private function findPackage(array $packages, string $name): ?PackageTagPlan
    {
        foreach ($packages as $pkg) {
            if ($pkg->packageName === $name) {
                return $pkg;
            }
        }

        return null;
    }
}
