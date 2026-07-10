<?php

declare(strict_types=1);

namespace Vortos\Deploy\Reclaim\Schedule;

use Psr\Log\LoggerInterface;
use Vortos\Cqrs\Attribute\AsCommandHandler;
use Vortos\Deploy\Definition\DeploymentDefinitionBuilder;
use Vortos\Deploy\Plan\ImagePrunePolicy;
use Vortos\Deploy\Driver\Docker\ImageReclaimer;
use Vortos\Deploy\State\CurrentReleaseStoreInterface;
use Vortos\Release\ReadModel\ManifestReadModelInterface;

/**
 * Handles the scheduled {@see ReclaimImagesCommand}. Thin by design — all docker logic lives in the
 * shared {@see ImageReclaimer}, the very same instance the deploy path uses, so the scheduled sweep
 * and the post-deploy sweep are byte-for-byte the same reclaim with the same safety guarantees.
 *
 * Resolves everything live at fire time:
 *   - the prune policy from the current deployment definition (config/deploy.php ->pruneImages);
 *   - the image repository from the latest recorded build manifest for the environment;
 *   - the release-authoritative protected digests (current live release + previous-for-rollback),
 *     which reclaim must never remove.
 *
 * Registered explicitly (tagged vortos.command_handler) by DeployExtension, mirroring how the
 * scheduler package wires PruneSchedulerRunsHandler.
 */
#[AsCommandHandler]
final class ReclaimImagesHandler
{
    public function __construct(
        private readonly ImageReclaimer $reclaimer,
        private readonly DeploymentDefinitionBuilder $definitionBuilder,
        private readonly CurrentReleaseStoreInterface $releaseStore,
        private readonly ?ManifestReadModelInterface $manifests = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(ReclaimImagesCommand $command): void
    {
        $env = $command->environment;

        // The build ledger names the repository to reclaim + the rollback-protected digests. Without
        // it we cannot know which repository is ours, so degrade to a safe no-op rather than guess.
        if ($this->manifests === null) {
            $this->logger?->info('image-gc: no manifest read model, scheduled reclaim skipped', ['env' => $env]);

            return;
        }

        $definition = $this->definitionBuilder->build();
        if (!$definition->pruneImages) {
            $this->logger?->info('image-gc: reclaim disabled by deployment definition', ['env' => $env]);

            return;
        }

        // The repository to reclaim is whatever the environment last deployed — read from the build
        // ledger rather than guessed. No manifest yet ⇒ nothing has ever deployed ⇒ nothing to do.
        $latest = $this->manifests->latestForEnvironment($env);
        if ($latest === null) {
            $this->logger?->info('image-gc: no build manifest for environment, nothing to reclaim', ['env' => $env]);

            return;
        }

        $policy = new ImagePrunePolicy(
            enabled: true,
            keep: $definition->pruneImagesKeep,
            builderCacheMaxAge: $definition->builderCacheMaxAge,
        );

        $report = $this->reclaimer->reclaim($latest->imageRepository, $policy, $this->protectedDigests($env));

        $this->logger?->info('image-gc: scheduled reclaim complete', [
            'env' => $env,
            'repository' => $latest->imageRepository,
            'removed' => $report->removed,
            'kept' => $report->kept,
            'notes' => $report->notes,
        ]);
    }

    /** @return list<string> */
    private function protectedDigests(string $env): array
    {
        $digests = [];

        $current = $this->releaseStore->currentRelease($env);
        if ($current !== null && $current->imageDigest !== '') {
            $digests[] = $current->imageDigest;
        }

        $previous = $this->manifests?->previousForEnvironment($env);
        if ($previous !== null) {
            $digests[] = $previous->imageDigest;
        }

        return array_values(array_unique(array_filter($digests)));
    }
}
