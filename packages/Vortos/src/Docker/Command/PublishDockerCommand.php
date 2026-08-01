<?php
declare(strict_types=1);

namespace Vortos\Docker\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Docker\Service\DockerFilePublisher;

#[AsCommand(
    name: 'vortos:docker:publish',
    description: 'Publish Docker files to your project'
)]
final class PublishDockerCommand extends Command
{
    /**
     * @param list<string> $corsOrigins the application's declared CORS allowlist, written into the
     *                                  edge config so a request rejected before PHP still answers
     *                                  with headers the browser can read
     */
    public function __construct(
        private readonly DockerFilePublisher $publisher,
        private readonly array $corsOrigins = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'runtime',
            'r',
            InputOption::VALUE_OPTIONAL,
            'Runtime to use: frankenphp or phpfpm',
            'frankenphp'
        )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview files without writing them')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Overwrite changed files without creating .bak copies')
            ->addOption('no-overwrite', null, InputOption::VALUE_NONE, 'Skip files that already exist with different content')
            ->addOption(
                'with-mercure',
                null,
                InputOption::VALUE_NONE,
                'Include the Mercure realtime hub in the Caddyfile. Requires VORTOS_MERCURE_JWT_SECRET '
                . 'and VORTOS_MERCURE_CORS_ORIGINS to be set in the runtime environment — the hub '
                . 'refuses to start without them, which is why it is opt-in.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runtime = (string) $input->getOption('runtime');
        $projectRoot = getcwd();

        try {
            $result = $this->publisher->publish(
                $runtime,
                $projectRoot,
                (bool) $input->getOption('dry-run'),
                !(bool) $input->getOption('no-backup'),
                !(bool) $input->getOption('no-overwrite'),
                [
                    'features'    => ['mercure' => (bool) $input->getOption('with-mercure')],
                    'corsOrigins' => $this->corsOrigins,
                ],
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // The published Caddyfile is committed and deployed, but the allowlist it was generated
        // from is whatever the *publishing* environment resolved — and a dev override of
        // `origins(['*'])` is a normal thing to have. Publishing from there would bake a
        // wildcard edge into the artifact that ships to production, where it would echo any
        // origin on rejected responses. Loud rather than silent: the file still matches the
        // middleware, which is correct, but nobody should learn about this from prod.
        if (in_array('*', $this->corsOrigins, true)) {
            $io->warning(
                'The resolved CORS allowlist contains "*", so the edge config now echoes any origin '
                . 'on rate-limited responses. That matches this environment, but the published file '
                . 'is deployed everywhere — re-publish with a production-like APP_ENV before shipping.'
            );
        }

        $io->success(sprintf(
            '%s Docker files %s for %s runtime.',
            count($result->copied),
            $input->getOption('dry-run') ? 'would be published' : 'published',
            $runtime,
        ));

        if ($result->backedUp !== []) {
            $io->section('Backups');
            $io->listing($result->backedUp);
        }

        if ($result->skipped !== []) {
            $io->section('Skipped unchanged or protected files');
            $io->listing($result->skipped);
        }

        return Command::SUCCESS;
    }
}
