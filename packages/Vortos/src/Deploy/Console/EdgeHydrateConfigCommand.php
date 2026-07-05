<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Deploy\Cutover\EdgeConfigGenerator;
use Vortos\Deploy\Cutover\State\EdgeStateStoreInterface;

/**
 * Reconstructs the edge Caddy config from the durable {@see EdgeStateStoreInterface} (GAP-D, D5).
 *
 * The edge is a stock caddy image with no PHP, so it cannot query the control-plane store itself. In
 * the edge compose an init step runs THIS command (from the app image) before Caddy starts: it reads
 * the environment's persisted routing intent, re-renders the Caddy JSON via the single-source-of-truth
 * {@see EdgeConfigGenerator}, and writes it to the path Caddy loads. A restarted or freshly-scaled
 * edge node therefore self-heals its active-color route — no local disk state, no lost route.
 *
 * Fail-closed: if no state has been recorded for the env there is nothing safe to serve, so it exits
 * non-zero rather than emitting a default/empty config.
 */
#[AsCommand(
    name: 'deploy:edge:hydrate-config',
    description: 'Render the edge Caddy config from the durable edge state store (edge boot init step).',
)]
final class EdgeHydrateConfigCommand extends Command
{
    public function __construct(
        private readonly EdgeStateStoreInterface $stateStore,
        private readonly EdgeConfigGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment name', 'production')
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Path to write the rendered Caddy config', '/config/caddy.json')
            ->addOption('admin-listen', null, InputOption::VALUE_REQUIRED, 'Admin bind address for the rendered config', 'localhost:2019')
            ->addOption(
                'fallback',
                null,
                InputOption::VALUE_REQUIRED,
                'Bootstrap config to use when no edge state exists yet (first boot, before the first cutover)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $out = (string) $input->getOption('out');
        $adminListen = (string) $input->getOption('admin-listen');
        $fallback = $input->getOption('fallback');
        $fallback = is_string($fallback) && $fallback !== '' ? $fallback : null;

        $state = $this->stateStore->load($env);
        if ($state === null) {
            // First boot, before the first cutover has recorded any state: fall back to the
            // bootstrap config (domain + TLS, no upstream yet) so the edge can still serve HTTPS and
            // accept the first /load. Only fail closed when there is neither state nor a fallback.
            if ($fallback !== null && is_file($fallback)) {
                $bootstrap = (string) file_get_contents($fallback);
                if ($this->writeAtomic($out, $bootstrap)) {
                    $output->writeln(sprintf('<info>No edge state for "%s"; hydrated from bootstrap %s → %s</info>', $env, $fallback, $out));

                    return self::SUCCESS;
                }
                $output->writeln(sprintf('<error>Cannot write edge config to %s</error>', $out));

                return self::FAILURE;
            }

            $output->writeln(sprintf(
                '<error>No edge state recorded for env "%s" and no --fallback given; refusing to render a default config.</error>',
                $env,
            ));

            return self::FAILURE;
        }

        $json = $this->generator->generateForRouteJson($state->toDesiredRoute(), $adminListen);

        if (!$this->writeAtomic($out, $json)) {
            $output->writeln(sprintf('<error>Cannot write edge config to %s</error>', $out));

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Edge config hydrated for "%s" (active=%s, version=%d) → %s</info>',
            $env,
            $state->activeColor->value,
            $state->version,
            $out,
        ));

        return self::SUCCESS;
    }

    private function writeAtomic(string $out, string $contents): bool
    {
        $dir = \dirname($out);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $tmp = $out . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents) === false || !rename($tmp, $out)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }
}
