<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\ObjectStore\Contract\LifecycleManagerInterface;
use Vortos\ObjectStore\Lifecycle\LifecycleConfiguration;
use Vortos\ObjectStore\Lifecycle\LifecyclePlan;

#[AsCommand(name: 'vortos:object-store:lifecycle', description: 'Show, plan, apply, or remove the managed object-store lifecycle rule')]
final class ObjectStoreLifecycleCommand extends Command
{
    private const ACTIONS = ['show', 'plan', 'apply', 'remove'];

    public function __construct(
        private readonly LifecycleManagerInterface $lifecycleManager,
        private readonly bool $requireConfirmation = true,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'Lifecycle action: show, plan, apply, remove', 'plan')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Confirm lifecycle mutation for apply/remove')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned mutation without writing')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');
        $json = (bool) $input->getOption('json');

        if (!in_array($action, self::ACTIONS, true)) {
            $this->writeError($io, $json, sprintf('Unsupported lifecycle action "%s".', $action));
            return Command::INVALID;
        }

        try {
            return match ($action) {
                'show' => $this->show($io, $json),
                'plan' => $this->plan($io, $json),
                'apply' => $this->apply($io, $json, (bool) $input->getOption('confirm'), (bool) $input->getOption('dry-run')),
                'remove' => $this->remove($io, $json, (bool) $input->getOption('confirm'), (bool) $input->getOption('dry-run')),
            };
        } catch (\Throwable $e) {
            $this->writeError($io, $json, $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function show(SymfonyStyle $io, bool $json): int
    {
        $current = $this->lifecycleManager->current();

        if ($json) {
            $io->writeln(json_encode(['rules' => $current->rules()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $io->title('Object Store Lifecycle');
        $this->renderRules($io, $current);

        return Command::SUCCESS;
    }

    private function plan(SymfonyStyle $io, bool $json): int
    {
        $plan = $this->lifecycleManager->planTemporaryUploadExpiry();
        $this->renderPlan($io, $plan, $json);

        return $plan->hasChanges() ? 2 : Command::SUCCESS;
    }

    private function apply(SymfonyStyle $io, bool $json, bool $confirmed, bool $dryRun): int
    {
        $plan = $this->lifecycleManager->planTemporaryUploadExpiry();
        if ($dryRun) {
            $this->renderPlan($io, $plan, $json);
            return $plan->hasChanges() ? 2 : Command::SUCCESS;
        }

        if ($this->requireConfirmation && !$confirmed) {
            $this->writeError($io, $json, 'Lifecycle apply requires --confirm.');
            return Command::FAILURE;
        }

        $applied = $this->lifecycleManager->apply($plan);
        $this->renderApplied($io, $json, $plan, $applied);

        return Command::SUCCESS;
    }

    private function remove(SymfonyStyle $io, bool $json, bool $confirmed, bool $dryRun): int
    {
        $plan = $this->lifecycleManager->planRemoveManagedRule();
        if ($dryRun) {
            $this->renderPlan($io, $plan, $json);
            return $plan->hasChanges() ? 2 : Command::SUCCESS;
        }

        if ($this->requireConfirmation && !$confirmed) {
            $this->writeError($io, $json, 'Lifecycle remove requires --confirm.');
            return Command::FAILURE;
        }

        $applied = $this->lifecycleManager->apply($plan);
        $this->renderApplied($io, $json, $plan, $applied);

        return Command::SUCCESS;
    }

    private function renderPlan(SymfonyStyle $io, LifecyclePlan $plan, bool $json): void
    {
        if ($json) {
            $io->writeln(json_encode(['plan' => $plan->toArray()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        $io->title('Object Store Lifecycle Plan');
        $io->definitionList(
            ['Change' => $plan->change()->value],
            ['Managed rule' => $plan->managedRuleId()],
            ['Current rules' => (string) count($plan->current()->rules())],
            ['Desired rules' => (string) count($plan->desired()->rules())],
        );

        if (!$plan->hasChanges()) {
            $io->success('Lifecycle configuration is already up to date.');
            return;
        }

        $io->section('Desired managed rule');
        $this->renderRule($io, $plan->desired()->rule($plan->managedRuleId()));
    }

    private function renderApplied(SymfonyStyle $io, bool $json, LifecyclePlan $plan, LifecycleConfiguration $applied): void
    {
        if ($json) {
            $io->writeln(json_encode([
                'applied' => true,
                'change' => $plan->change()->value,
                'rules' => $applied->rules(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        $io->success(sprintf('Lifecycle configuration applied (%s).', $plan->change()->value));
    }

    private function renderRules(SymfonyStyle $io, LifecycleConfiguration $configuration): void
    {
        if ($configuration->rules() === []) {
            $io->comment('No lifecycle rules configured.');
            return;
        }

        $io->table(['ID', 'Status', 'Prefix', 'Expiration days'], array_map(
            fn(array $rule): array => $this->rowForRule($rule),
            $configuration->rules(),
        ));
    }

    /** @param array<string, mixed>|null $rule */
    private function renderRule(SymfonyStyle $io, ?array $rule): void
    {
        if ($rule === null) {
            $io->comment('No managed rule will be present.');
            return;
        }

        $io->table(['ID', 'Status', 'Prefix', 'Expiration days'], [$this->rowForRule($rule)]);
    }

    /** @param array<string, mixed> $rule */
    private function rowForRule(array $rule): array
    {
        return [
            (string) ($rule['ID'] ?? ''),
            (string) ($rule['Status'] ?? ''),
            (string) (($rule['Filter']['Prefix'] ?? null) ?? ($rule['Prefix'] ?? '')),
            (string) ($rule['Expiration']['Days'] ?? ''),
        ];
    }

    private function writeError(SymfonyStyle $io, bool $json, string $message): void
    {
        if ($json) {
            $io->writeln(json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        $io->error($message);
    }
}
