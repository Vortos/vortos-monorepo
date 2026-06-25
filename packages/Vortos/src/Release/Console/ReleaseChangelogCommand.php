<?php

declare(strict_types=1);

namespace Vortos\Release\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Release\Changelog\ChangelogRenderer;
use Vortos\Release\Git\GitRepositoryInterface;
use Vortos\Release\Service\ChangelogGenerator;
use Vortos\Release\Service\PackageDiscovery;
use Vortos\Release\Service\VersionResolver;
use Vortos\Release\Version\BumpCalculator;
use Vortos\Release\Version\ConventionalCommitParser;
use Vortos\Release\Version\VersioningStrategyInterface;

#[AsCommand(
    name: 'vortos:release:changelog',
    description: 'Generate changelog from conventional commits.',
)]
final class ReleaseChangelogCommand extends Command
{
    public function __construct(
        private readonly GitRepositoryInterface $git,
        private readonly PackageDiscovery $packageDiscovery,
        private readonly VersioningStrategyInterface $strategy,
        private readonly ChangelogGenerator $changelogGenerator,
        private readonly ChangelogRenderer $renderer,
        private readonly ConventionalCommitParser $commitParser,
        private readonly BumpCalculator $bumpCalculator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('package', null, InputOption::VALUE_REQUIRED, 'Generate for a specific package only')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Write CHANGELOG.md to each package directory')
            ->addOption('unreleased', null, InputOption::VALUE_NONE, 'Show unreleased changes only');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packages = $this->packageDiscovery->discover();

        $pkgFilter = $input->getOption('package');
        if (\is_string($pkgFilter) && $pkgFilter !== '') {
            $packages = array_values(array_filter(
                $packages,
                static fn ($p) => $p->name === $pkgFilter,
            ));

            if ($packages === []) {
                $output->writeln(sprintf('<error>Package "%s" not found.</error>', $pkgFilter));

                return self::FAILURE;
            }
        }

        $versionResolver = new VersionResolver($this->git, $this->strategy);
        $latestTag = $versionResolver->latestTag();
        $currentVersion = $versionResolver->currentVersion();

        $rawCommits = $this->git->commitsBetween($latestTag, 'HEAD');
        $parsedCommits = [];
        foreach ($rawCommits as $raw) {
            $parsedCommits[] = $this->commitParser->parse($raw->rawMessage, $raw->sha);
        }

        $bump = $this->bumpCalculator->calculate($parsedCommits, !$currentVersion->isStable());
        $nextVersion = $this->strategy->nextVersion($currentVersion, $bump);

        foreach ($packages as $pkg) {
            $changelog = $this->changelogGenerator->generate(
                rawCommits: $rawCommits,
                version: $nextVersion,
                packageName: $pkg->name,
            );

            $rendered = $this->renderer->render($changelog);

            if ($input->getOption('write')) {
                $path = $pkg->path . '/CHANGELOG.md';
                $existing = is_file($path) ? file_get_contents($path) : '';
                $header = "# Changelog\n\nAll notable changes to `{$pkg->name}` will be documented in this file.\n\n";

                if (\is_string($existing) && str_starts_with($existing, '# Changelog')) {
                    $afterHeader = strpos($existing, "\n## ");
                    $content = $afterHeader !== false
                        ? substr($existing, 0, $afterHeader) . "\n" . $rendered . substr($existing, $afterHeader)
                        : $existing . "\n" . $rendered;
                } else {
                    $content = $header . $rendered;
                }

                file_put_contents($path, $content);
                $output->writeln(sprintf('<info>Written: %s</info>', $path));
            } else {
                $output->writeln(sprintf('<info>%s</info>', $pkg->name));
                $output->writeln($rendered);
            }
        }

        return self::SUCCESS;
    }
}
