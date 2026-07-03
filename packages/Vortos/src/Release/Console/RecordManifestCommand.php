<?php

declare(strict_types=1);

namespace Vortos\Release\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Manifest\ManifestAlreadyExistsException;
use Vortos\Release\Manifest\Provenance;
use Vortos\Release\Migration\AvailableMigrationSetReaderInterface;
use Vortos\Release\ReadModel\ManifestRepositoryInterface;

/**
 * Records an immutable build manifest for an environment. Run in the deploy job
 * (which has both the build's migrations on disk and database access) AFTER the image
 * is built+pushed and BEFORE 'deploy' — it is what makes a desired build resolvable by
 * the deploy runner. The schema fingerprint is derived from the build's available
 * migrations, not the applied set, because migrations have not run yet at this point.
 *
 * Idempotent on build id: a re-run with the same --build-id is a success, not an error,
 * so a retried CI job does not fail.
 */
#[AsCommand(
    name: 'vortos:release:record-manifest',
    description: 'Record an immutable build manifest (repository + digest + schema) for an environment.',
)]
final class RecordManifestCommand extends Command
{
    public function __construct(
        private readonly ManifestRepositoryInterface $manifests,
        private readonly AvailableMigrationSetReaderInterface $migrations,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment (e.g. production, staging).')
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'Fully-qualified image repository (no tag/digest), e.g. ghcr.io/acme/app.')
            ->addOption('digest', null, InputOption::VALUE_REQUIRED, 'Image digest sha256:<64 hex>.')
            ->addOption('git-sha', null, InputOption::VALUE_REQUIRED, 'Git commit SHA of the build.')
            ->addOption('build-id', null, InputOption::VALUE_REQUIRED, 'Build id (defaults to the first 12 chars of --git-sha).')
            ->addOption('arch', null, InputOption::VALUE_REQUIRED, 'Target arch: arm64|amd64 or linux/arm64|linux/amd64.', 'arm64')
            ->addOption('builder-id', null, InputOption::VALUE_REQUIRED, 'Provenance builder id (e.g. github-actions).')
            ->addOption('base-image-digest', null, InputOption::VALUE_REQUIRED, 'Provenance base image digest.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');

        $env = $this->requireOption($input, 'env');
        $repository = $this->requireOption($input, 'repository');
        $digest = $this->requireOption($input, 'digest');
        $gitSha = $this->requireOption($input, 'git-sha');

        if ($env === null || $repository === null || $digest === null || $gitSha === null) {
            return $this->fail($output, $json, 'Missing required option; --env, --repository, --digest and --git-sha are all required.');
        }

        $buildIdOpt = $input->getOption('build-id');
        $buildId = \is_string($buildIdOpt) && $buildIdOpt !== '' ? $buildIdOpt : substr($gitSha, 0, 12);

        $arch = $this->resolveArch((string) $input->getOption('arch'));
        if ($arch === null) {
            return $this->fail($output, $json, sprintf('Unknown --arch "%s"; expected arm64|amd64 or linux/arm64|linux/amd64.', (string) $input->getOption('arch')));
        }

        $builderId = $input->getOption('builder-id');
        $baseImageDigest = $input->getOption('base-image-digest');
        $provenance = (\is_string($builderId) && $builderId !== '') || (\is_string($baseImageDigest) && $baseImageDigest !== '')
            ? new Provenance(
                builderId: \is_string($builderId) && $builderId !== '' ? $builderId : 'unknown',
                baseImageDigest: \is_string($baseImageDigest) && $baseImageDigest !== '' ? $baseImageDigest : null,
            )
            : null;

        try {
            $manifest = new BuildManifest(
                buildId: $buildId,
                gitSha: $gitSha,
                imageRepository: $repository,
                imageDigest: $digest,
                targetArch: $arch,
                environment: $env,
                schemaFingerprint: $this->migrations->availableSet(),
                createdAt: new \DateTimeImmutable(),
                provenance: $provenance,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail($output, $json, $e->getMessage());
        }

        try {
            $this->manifests->record($manifest);
        } catch (ManifestAlreadyExistsException) {
            // Idempotent: a retried CI job re-recording the same build id is a success.
            return $this->succeed($output, $json, $manifest, 'already-recorded');
        }

        return $this->succeed($output, $json, $manifest, 'recorded');
    }

    private function requireOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function resolveArch(string $arch): ?Arch
    {
        return match ($arch) {
            'arm64', 'linux/arm64' => Arch::Arm64,
            'amd64', 'linux/amd64' => Arch::Amd64,
            default => Arch::tryFrom($arch),
        };
    }

    private function succeed(OutputInterface $output, bool $json, BuildManifest $manifest, string $status): int
    {
        if ($json) {
            $output->writeln(json_encode([
                'status' => $status,
                'build_id' => $manifest->buildId,
                'environment' => $manifest->environment,
                'image' => $manifest->pullReference(),
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Manifest %s: build=%s env=%s image=%s</info>',
            $status,
            $manifest->buildId,
            $manifest->environment,
            $manifest->pullReference(),
        ));

        return Command::SUCCESS;
    }

    private function fail(OutputInterface $output, bool $json, string $message): int
    {
        if ($json) {
            $output->writeln(json_encode(['status' => 'error', 'error' => $message], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(sprintf('<error>%s</error>', $message));
        }

        return Command::FAILURE;
    }
}
