<?php

declare(strict_types=1);

namespace Vortos\Alerts\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\AlertRuleValidationException;
use Vortos\Alerts\Rule\AlertRuleValidator;
use Vortos\Observability\Slo\SloRegistry;

/** Validates declared alert rule config; exits non-zero on any invalid/dangling rule. Mirrored by the `deploy:doctor` check. */
#[AsCommand(
    name: 'vortos:alerts:rules:validate',
    description: 'Validate declared alert rule config (thresholds, duplicate ids, dangling SLO refs)',
)]
final class ValidateRulesCommand extends Command
{
    public function __construct(
        private readonly AlertRuleSet $rules,
        private readonly AlertRuleValidator $validator,
        private readonly ?SloRegistry $sloRegistry = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->validator->validate($this->rules, $this->sloRegistry);
        } catch (AlertRuleValidationException $e) {
            foreach ($e->violations as $violation) {
                $io->error($violation);
            }

            return Command::FAILURE;
        }

        $io->success(sprintf('%d alert rule(s) valid.', count($this->rules->all())));

        return Command::SUCCESS;
    }
}
