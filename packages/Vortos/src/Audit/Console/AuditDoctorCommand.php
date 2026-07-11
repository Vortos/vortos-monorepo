<?php

declare(strict_types=1);

namespace Vortos\Audit\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Audit\Doctor\AuditDoctor;
use Vortos\Audit\Doctor\AuditDoctorCheck;

#[AsCommand(name: 'vortos:audit:doctor', description: 'Diagnose the audit subsystem configuration and health.')]
final class AuditDoctorCommand extends Command
{
    public function __construct(private readonly AuditDoctor $doctor) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Audit Doctor');

        $rows = [];
        foreach ($this->doctor->run() as $check) {
            $icon = match ($check->status) {
                AuditDoctorCheck::OK   => '<info>OK</info>',
                AuditDoctorCheck::WARN => '<comment>WARN</comment>',
                default                => '<error>FAIL</error>',
            };
            $rows[] = [$icon, $check->name, $check->message];
        }
        $io->table(['Status', 'Check', 'Detail'], $rows);

        if ($this->doctor->hasFailure()) {
            $io->error('Audit subsystem has failing checks.');
            return Command::FAILURE;
        }

        $io->success('No failing checks.');
        return Command::SUCCESS;
    }
}
