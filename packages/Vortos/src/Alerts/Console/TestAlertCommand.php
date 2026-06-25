<?php

declare(strict_types=1);

namespace Vortos\Alerts\Console;

use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Severity;

/** Sends a synthetic alert at a chosen severity through the full routing → dedupe → delivery path. */
#[AsCommand(
    name: 'vortos:alerts:test',
    description: 'Send a synthetic alert through the full pipeline to a chosen env',
)]
final class TestAlertCommand extends Command
{
    public function __construct(
        private readonly AlertDispatcherInterface $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('env', InputArgument::REQUIRED, 'Target environment')
            ->addOption('severity', 's', InputOption::VALUE_REQUIRED, 'info|warning|critical', 'warning');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $severity = Severity::tryFrom((string) $input->getOption('severity'));
        if ($severity === null) {
            $io->error('Invalid --severity; expected info, warning, or critical.');

            return Command::FAILURE;
        }

        $event = AlertEvent::scrubbed(
            ruleId: 'alerts.synthetic_test',
            severity: $severity,
            title: 'Synthetic test alert',
            summary: 'Sent via vortos:alerts:test — proves the wiring without waiting for a real incident.',
            source: AlertSource::Synthetic,
            env: (string) $input->getArgument('env'),
            tenantId: null,
            labels: ['synthetic' => 'true'],
            annotations: [],
            links: [],
            occurredAt: new DateTimeImmutable(),
        );

        $result = $this->dispatcher->dispatch($event);

        $io->writeln(sprintf('Dedupe decision: %s', $result->decision->value));
        foreach ($result->results as $delivery) {
            $io->writeln(sprintf('  -> %s: %s%s', $delivery->channelKey, $delivery->outcome->value, $delivery->reason !== null ? " ({$delivery->reason})" : ''));
        }

        $io->success('Synthetic alert dispatched.');

        return Command::SUCCESS;
    }
}
