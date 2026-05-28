<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\ValueObject\Email;

/**
 * Sends a test email through the configured mailer to verify end-to-end delivery.
 *
 * Usage:
 *   bin/console vortos:ses:send:test you@example.com
 *   bin/console vortos:ses:send:test you@example.com --from=noreply@yourdomain.com --subject="Custom subject"
 */
#[AsCommand(
    name:        'vortos:ses:send:test',
    description: 'Send a test email to verify SES delivery is working.',
)]
final class SesSendTestCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $defaultFromAddress,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('to', InputArgument::REQUIRED, 'Recipient email address')
            ->addOption('from',    null, InputOption::VALUE_REQUIRED, 'Sender address (overrides default)')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Email subject', 'Vortos SES Test Email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $to      = (string) $input->getArgument('to');
        $from    = (string) ($input->getOption('from') ?? $this->defaultFromAddress);
        $subject = (string) $input->getOption('subject');

        if ($from === '') {
            $io->error('No from address configured. Pass --from or set SES_FROM_ADDRESS.');
            return Command::FAILURE;
        }

        $io->text(sprintf('Sending test email to <info>%s</info>…', $to));

        try {
            $email = Email::new()
                ->from($from)
                ->to($to)
                ->subject($subject)
                ->htmlBody(sprintf(
                    '<p>This is a test email sent at <strong>%s</strong> via Vortos SES.</p>',
                    (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ))
                ->textBody(sprintf(
                    'This is a test email sent at %s via Vortos SES.',
                    (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ));

            $sent = $this->mailer->send($email);

            $io->success(sprintf(
                'Test email sent successfully. Message ID: %s',
                $sent->messageId(),
            ));
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to send test email: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
