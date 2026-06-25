<?php

declare(strict_types=1);

namespace Vortos\Secrets\Console;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Vortos\Secrets\Provider\SecretsProviderRegistry;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretValue;

/**
 * Sets a secret value. The value is read from stdin or a hidden interactive
 * prompt — **never** from a command argument or option, so it never appears in
 * argv, the process table, or shell history.
 */
#[AsCommand(
    name: 'secrets:set',
    description: 'Sets a secret value, read from stdin or a hidden prompt — never argv.',
)]
final class SecretsSetCommand extends Command
{
    /** @var resource */
    private readonly mixed $stdin;

    /** @param resource|null $stdin override for testing only; defaults to the real STDIN stream */
    public function __construct(private readonly SecretsProviderRegistry $providers, mixed $stdin = null)
    {
        parent::__construct();

        $this->stdin = $stdin ?? STDIN;
        $this->setHelperSet(new HelperSet(['question' => new QuestionHelper()]));
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Secret key to set')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Provider driver key for the target environment', 'env')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read the value from stdin instead of an interactive hidden prompt (for CI/piping)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = $this->providers->provider((string) $input->getOption('env'));
        $key = SecretKey::fromString((string) $input->getArgument('key'));

        $plaintext = $input->getOption('stdin')
            ? $this->readFromStdin()
            : $this->readFromHiddenPrompt($input, $output);

        if ($plaintext === '') {
            $output->writeln('<error>Secret value must not be empty.</error>');

            return self::FAILURE;
        }

        $version = $provider->put($key, SecretValue::fromString($plaintext));

        $output->writeln(sprintf('<info>Set %s: version %s.</info>', $key->value(), $version->versionId));

        return self::SUCCESS;
    }

    private function readFromStdin(): string
    {
        $contents = stream_get_contents($this->stdin);
        if ($contents === false) {
            throw new RuntimeException('Failed to read secret value from stdin.');
        }

        return rtrim($contents, "\n\r");
    }

    private function readFromHiddenPrompt(InputInterface $input, OutputInterface $output): string
    {
        $question = new Question('Secret value: ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        return (string) $helper->ask($input, $output, $question);
    }
}
