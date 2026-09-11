<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Exposure\ExposureRecord;
use Vortos\FeatureFlags\Exposure\Readout\ExperimentReadout;
use Vortos\FeatureFlags\Exposure\Readout\ReadoutResult;

/**
 * Reads out an experiment from the first-party exposure ledger.
 *
 * Prints the arms, the comparison, and — with equal prominence — every reason not to believe
 * it. The warnings are not a footnote: a readout is most dangerous when it is small, early,
 * or contaminated, and all three of those states produce numbers that look perfectly
 * ordinary.
 */
#[AsCommand(
    name: 'vortos:flags:experiment:readout',
    description: 'Compute an experiment result from the first-party exposure ledger',
)]
final class FlagsExperimentReadoutCommand extends Command
{
    public function __construct(private readonly ExperimentReadout $readout)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('flag', InputArgument::REQUIRED, 'Flag name')
            ->addArgument('metric', InputArgument::REQUIRED, 'Outcome metric name, as the application defines it')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Window start (YYYY-MM-DD, UTC, inclusive)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Window end (YYYY-MM-DD, UTC, inclusive)')
            ->addOption(
                'control',
                null,
                InputOption::VALUE_REQUIRED,
                'Variant to treat as the baseline arm',
                ExposureRecord::BOOL_FALSE,
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON instead of a table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $utc = new DateTimeZone('UTC');

        // Yesterday, not today: the default window deliberately excludes the day still being
        // written, so the common invocation cannot be a peek at a running experiment.
        $to   = new DateTimeImmutable((string) ($input->getOption('to') ?? 'yesterday'), $utc);
        $from = new DateTimeImmutable((string) ($input->getOption('from') ?? '-14 days'), $utc);

        $result = $this->readout->readout(
            (string) $input->getArgument('flag'),
            (string) $input->getArgument('metric'),
            $from,
            $to,
            (string) $input->getOption('control'),
        );

        if ($input->getOption('json')) {
            $output->writeln($this->toJson($result));

            return Command::SUCCESS;
        }

        $this->render($result, $output);

        return Command::SUCCESS;
    }

    private function render(ReadoutResult $result, OutputInterface $output): void
    {
        $output->writeln(sprintf(
            "\n<info>%s</info> — metric <info>%s</info>, %s to %s (UTC)\n",
            $result->flag,
            $result->metric,
            $result->from,
            $result->to,
        ));

        $output->writeln(sprintf('  %-24s %10s %12s %10s', 'VARIANT', 'SUBJECTS', 'CONVERSIONS', 'RATE'));
        foreach ($result->variants as $variant) {
            $output->writeln(sprintf(
                '  %-24s %10d %12d %9s',
                $variant->variant === '' ? '(none)' : $variant->variant,
                $variant->subjects,
                $variant->conversions,
                $variant->rate() === null ? '—' : number_format($variant->rate() * 100, 2) . '%',
            ));
        }

        if ($result->pValue !== null) {
            $lift = $result->relativeLift();

            $output->writeln(sprintf("\n  Relative lift : %s", $lift === null
                ? '—'
                : sprintf('%+.2f%%', $lift * 100)));

            $output->writeln(sprintf('  p-value       : %.4f', $result->pValue));

            if ($result->interval !== null) {
                $output->writeln(sprintf(
                    '  95%% CI (abs)  : %+.2f%% to %+.2f%%',
                    $result->interval[0] * 100,
                    $result->interval[1] * 100,
                ));
            }

            $output->writeln(sprintf(
                '  Verdict       : %s',
                $result->isSignificant()
                    ? '<info>significant at p < 0.05</info>'
                    : '<comment>not significant</comment>',
            ));
        }

        foreach ($result->warnings as $warning) {
            $output->writeln("\n  <comment>! " . wordwrap($warning, 92, "\n    ") . '</comment>');
        }

        // Printed unconditionally, including on a clean significant result. It is a property
        // of how the data was gathered, not a caveat that some readouts escape.
        $output->writeln(
            "\n  <comment>Exposures are first-party and unsampled, so this covers every subject — "
            . "including\n  those who declined analytics consent. It is not comparable to a "
            . "PostHog readout of the\n  same flag, which sees only consenting subjects.</comment>\n"
        );
    }

    private function toJson(ReadoutResult $result): string
    {
        return (string) json_encode([
            'flag'    => $result->flag,
            'metric'  => $result->metric,
            'from'    => $result->from,
            'to'      => $result->to,
            'variants' => array_map(static fn ($v): array => [
                'variant'     => $v->variant,
                'subjects'    => $v->subjects,
                'conversions' => $v->conversions,
                'rate'        => $v->rate(),
            ], $result->variants),
            'p_value'       => $result->pValue,
            'interval'      => $result->interval,
            'relative_lift' => $result->relativeLift(),
            'reliable'      => $result->reliable,
            'significant'   => $result->isSignificant(),
            'warnings'      => $result->warnings,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
