<?php

declare(strict_types=1);

namespace Vortos\Make\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Engine\GeneratorEngine;

#[AsCommand(
    name: 'vortos:make:hook',
    description: 'Generate a messaging lifecycle hook',
)]
final class MakeHookCommand extends Command
{
    private const array DISPATCH_TYPES = ['before-dispatch', 'after-dispatch'];
    private const array CONSUME_TYPES  = ['before-consume', 'after-consume'];
    private const array VALID_TYPES    = ['before-dispatch', 'after-dispatch', 'before-consume', 'after-consume', 'pre-send'];

    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Hook name without "Hook" suffix (e.g. LogDispatch)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. User)')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Hook type: before-dispatch | after-dispatch | before-consume | after-consume | pre-send');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = (string) $input->getArgument('name');
        $context = (string) $input->getOption('context');
        $type    = (string) $input->getOption('type');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        if (!in_array($type, self::VALID_TYPES, true)) {
            $output->writeln('<error>--type is required. Valid: ' . implode(', ', self::VALID_TYPES) . '</error>');
            return Command::FAILURE;
        }

        $attribute = $this->toAttributeName($type);

        [$stub, $vars] = match (true) {
            in_array($type, self::DISPATCH_TYPES, true) => ['hook-dispatch', ['HookAttribute' => $attribute]],
            in_array($type, self::CONSUME_TYPES, true)  => ['hook-consume',  ['HookAttribute' => $attribute]],
            default                                      => ['hook-presend',  []],
        };

        $vars = array_merge([
            'Namespace' => "App\\{$context}",
            'ClassName' => $name,
        ], $vars);

        $output->writeln("<info>vortos:make:hook</info> {$name} --context={$context} --type={$type}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Infrastructure/Messaging/{$name}Hook.php",
            $this->engine->render($stub, $vars),
            $output,
        );

        return Command::SUCCESS;
    }

    private function toAttributeName(string $type): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $type)));
    }
}
