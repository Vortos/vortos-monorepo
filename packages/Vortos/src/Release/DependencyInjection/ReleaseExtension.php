<?php

declare(strict_types=1);

namespace Vortos\Release\DependencyInjection;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Release\Changelog\ChangelogRenderer;
use Vortos\Release\Audit\NullReleaseAuditEmitter;
use Vortos\Release\Audit\ReleaseAuditEmitterInterface;
use Vortos\Release\Console\ReleaseChangelogCommand;
use Vortos\Release\Console\ReleaseTagCommand;
use Vortos\Release\Git\GitRemoteResolver;
use Vortos\Release\Git\GitRepositoryInterface;
use Vortos\Release\Git\Process\ProcessGitRepository;
use Vortos\Release\Migration\AppliedMigrationSetReaderInterface;
use Vortos\Release\Migration\DoctrineAppliedMigrationSetReader;
use Vortos\Release\ReadModel\DbalManifestReadModel;
use Vortos\Release\ReadModel\DbalManifestRepository;
use Vortos\Release\ReadModel\ManifestReadModelInterface;
use Vortos\Release\ReadModel\ManifestRepositoryInterface;
use Vortos\Release\Service\ChangelogGenerator;
use Vortos\Release\Service\CoordinatedTagger;
use Vortos\Release\Service\PackageDiscovery;
use Vortos\Release\Service\ReleasePlanner;
use Vortos\Release\Service\VersionSkewGuard;
use Vortos\Release\Tagging\File\FileTaggingTransactionStore;
use Vortos\Release\Tagging\TaggingTransactionStoreInterface;
use Vortos\Release\Version\AlphaCounterStrategy;
use Vortos\Release\Version\BumpCalculator;
use Vortos\Release\Version\ConventionalCommitParser;
use Vortos\Release\Version\VersioningStrategyInterface;

final class ReleaseExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_release';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $manifestTable = $prefix . 'release_build_manifests';
        $envStateTable = $prefix . 'release_env_schema_state';

        $container->register(DoctrineAppliedMigrationSetReader::class, DoctrineAppliedMigrationSetReader::class)
            ->setArgument('$factoryProvider', new Reference(DependencyFactoryProviderInterface::class))
            ->setPublic(false);

        $container->setAlias(AppliedMigrationSetReaderInterface::class, DoctrineAppliedMigrationSetReader::class)
            ->setPublic(false);

        $container->register(DbalManifestRepository::class, DbalManifestRepository::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$manifestTable', $manifestTable)
            ->setArgument('$envStateTable', $envStateTable)
            ->setPublic(false);

        $container->setAlias(ManifestRepositoryInterface::class, DbalManifestRepository::class)
            ->setPublic(false);

        $container->register(DbalManifestReadModel::class, DbalManifestReadModel::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$manifestTable', $manifestTable)
            ->setArgument('$envStateTable', $envStateTable)
            ->setPublic(false);

        $container->setAlias(ManifestReadModelInterface::class, DbalManifestReadModel::class)
            ->setPublic(false);

        // ── Block 4: semver, changelog, coordinated tagging ──

        $projectDir = $container->hasParameter('kernel.project_dir')
            ? (string) $container->getParameter('kernel.project_dir')
            : '%kernel.project_dir%';

        $packagesDir = $projectDir . '/packages/Vortos/src';

        $container->register(ProcessGitRepository::class, ProcessGitRepository::class)
            ->setArgument('$workingDir', $projectDir)
            ->setPublic(false);

        $container->setAlias(GitRepositoryInterface::class, ProcessGitRepository::class)
            ->setPublic(false);

        $container->register(GitRemoteResolver::class, GitRemoteResolver::class)
            ->setPublic(false);

        $container->register(AlphaCounterStrategy::class, AlphaCounterStrategy::class)
            ->setPublic(false);

        $container->setAlias(VersioningStrategyInterface::class, AlphaCounterStrategy::class)
            ->setPublic(false);

        $container->register(ConventionalCommitParser::class, ConventionalCommitParser::class)
            ->setPublic(false);

        $container->register(BumpCalculator::class, BumpCalculator::class)
            ->setPublic(false);

        $container->register(ChangelogRenderer::class, ChangelogRenderer::class)
            ->setPublic(false);

        $container->register(ChangelogGenerator::class, ChangelogGenerator::class)
            ->setArgument('$parser', new Reference(ConventionalCommitParser::class))
            ->setArgument('$manifestReadModel', new Reference(ManifestReadModelInterface::class))
            ->setPublic(false);

        $container->register(PackageDiscovery::class, PackageDiscovery::class)
            ->setArgument('$packagesDir', $packagesDir)
            ->setPublic(false);

        $container->register(ReleasePlanner::class, ReleasePlanner::class)
            ->setArgument('$bumpCalculator', new Reference(BumpCalculator::class))
            ->setArgument('$changelogGenerator', new Reference(ChangelogGenerator::class))
            ->setArgument('$strategy', new Reference(VersioningStrategyInterface::class))
            ->setPublic(false);

        $container->register(VersionSkewGuard::class, VersionSkewGuard::class)
            ->setArgument('$git', new Reference(GitRepositoryInterface::class))
            ->setArgument('$packagesBaseDir', $packagesDir)
            ->setPublic(false);

        $transactionDir = $projectDir . '/var/release/transactions';

        $container->register(FileTaggingTransactionStore::class, FileTaggingTransactionStore::class)
            ->setArgument('$directory', $transactionDir)
            ->setPublic(false);

        $container->setAlias(TaggingTransactionStoreInterface::class, FileTaggingTransactionStore::class)
            ->setPublic(false);

        $container->register(NullReleaseAuditEmitter::class, NullReleaseAuditEmitter::class)
            ->setPublic(false);

        if (!$container->has(ReleaseAuditEmitterInterface::class)) {
            $container->setAlias(ReleaseAuditEmitterInterface::class, NullReleaseAuditEmitter::class)
                ->setPublic(false);
        }

        $container->register(CoordinatedTagger::class, CoordinatedTagger::class)
            ->setArgument('$git', new Reference(GitRepositoryInterface::class))
            ->setArgument('$store', new Reference(TaggingTransactionStoreInterface::class))
            ->setArgument('$auditEmitter', new Reference(ReleaseAuditEmitterInterface::class))
            ->setPublic(false);

        $container->register(ReleaseTagCommand::class, ReleaseTagCommand::class)
            ->setArgument('$git', new Reference(GitRepositoryInterface::class))
            ->setArgument('$packageDiscovery', new Reference(PackageDiscovery::class))
            ->setArgument('$strategy', new Reference(VersioningStrategyInterface::class))
            ->setArgument('$planner', new Reference(ReleasePlanner::class))
            ->setArgument('$tagger', new Reference(CoordinatedTagger::class))
            ->setArgument('$skewGuard', new Reference(VersionSkewGuard::class))
            ->setArgument('$remoteResolver', new Reference(GitRemoteResolver::class))
            ->setArgument('$commitParser', new Reference(ConventionalCommitParser::class))
            ->addTag('console.command')
            ->setPublic(false);

        $container->register(ReleaseChangelogCommand::class, ReleaseChangelogCommand::class)
            ->setArgument('$git', new Reference(GitRepositoryInterface::class))
            ->setArgument('$packageDiscovery', new Reference(PackageDiscovery::class))
            ->setArgument('$strategy', new Reference(VersioningStrategyInterface::class))
            ->setArgument('$changelogGenerator', new Reference(ChangelogGenerator::class))
            ->setArgument('$renderer', new Reference(ChangelogRenderer::class))
            ->setArgument('$commitParser', new Reference(ConventionalCommitParser::class))
            ->setArgument('$bumpCalculator', new Reference(BumpCalculator::class))
            ->addTag('console.command')
            ->setPublic(false);
    }
}
