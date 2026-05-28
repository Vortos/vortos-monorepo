<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Command\Make;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Engine\GeneratorEngine;

/**
 * Generates a skeleton SES email middleware class.
 *
 * Usage:
 *   bin/console vortos:ses:make:email-middleware LogRecipients --context=Notification
 *   bin/console vortos:ses:make:email-middleware RateLimit --context=Billing --priority=550
 */
#[AsCommand(
    name:        'vortos:ses:make:email-middleware',
    description: 'Generate a skeleton SES email middleware.',
)]
final class MakeSesEmailMiddlewareCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Class name prefix (without "Middleware" suffix)')
            ->addOption('context',  'c',  InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. Notification)')
            ->addOption('priority', null, InputOption::VALUE_REQUIRED, 'Middleware priority — higher runs outermost', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name     = (string) $input->getArgument('name');
        $context  = (string) ($input->getOption('context') ?? '');
        $priority = (string) ($input->getOption('priority') ?? '100');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=Notification</error>');
            return Command::FAILURE;
        }

        $vars = [
            'Namespace' => "App\\{$context}",
            'ClassName' => $name,
            'Priority'  => $priority,
        ];

        $output->writeln("<info>vortos:ses:make:email-middleware</info> {$name} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Infrastructure/Email/{$name}Middleware.php",
            $this->engine->render('email-middleware', $vars),
            $output,
        );

        $output->writeln('');
        $output->writeln(
            sprintf(
                '<info>%sMiddleware</info> auto-registers via <info>#[AsEmailMiddleware(priority: %s)]</info> — no manual wiring needed.',
                $name,
                $priority,
            ),
        );

        return Command::SUCCESS;
    }
}
