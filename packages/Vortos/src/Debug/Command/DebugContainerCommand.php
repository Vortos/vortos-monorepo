<?php

declare(strict_types=1);

namespace Vortos\Debug\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:debug:container',
    description: 'List all registered container services',
)]
final class DebugContainerCommand extends Command
{
    public function __construct(
        private readonly array $services,
        private readonly array $aliases,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('filter', 'f', InputOption::VALUE_REQUIRED, 'Filter by service ID or class name')
            ->addOption('tag', 't', InputOption::VALUE_REQUIRED, 'Filter by tag')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Show full details for a single service')
            ->addOption('aliases', null, InputOption::VALUE_NONE, 'Show aliases')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($serviceId = $input->getOption('service')) {
            return $this->renderSingle($serviceId, $output);
        }

        $services = $this->applyFilters($this->services, $input);

        if ($input->getOption('json')) {
            $payload = ['services' => $services];
            if ($input->getOption('aliases')) {
                $payload['aliases'] = $this->aliases;
            }
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $publicCount = count(array_filter($services, fn($s) => $s['public']));

        $output->writeln('');
        $output->writeln(sprintf(
            ' <fg=white;options=bold>Vortos Container</> <fg=gray>(%d services, %d public)</>',
            count($services),
            $publicCount,
        ));
        $output->writeln('');

        if (empty($services)) {
            $output->writeln(' <fg=yellow>No services match the given filters.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $tableStyle = (new TableStyle())
            ->setHorizontalBorderChars('')
            ->setVerticalBorderChars(' ')
            ->setCrossingChars('', '', '', '', '', '', '', '', '');

        $table = new Table($output);
        $table->setStyle($tableStyle);
        $table->setHeaders([
            '<fg=gray>Service ID</>',
            '<fg=gray>Class</>',
            '<fg=gray>Pub</>',
            '<fg=gray>Shared</>',
            '<fg=gray>Lazy</>',
            '<fg=gray>Tags</>',
        ]);

        foreach ($services as $id => $meta) {
            $table->addRow([
                sprintf('<fg=white>%s</>', $this->truncate($id, 55)),
                sprintf('<fg=gray>%s</>', $this->truncate($this->shortClass($meta['class']), 40)),
                $meta['public'] ? '<fg=green>✓</>' : '<fg=gray>✗</>',
                $meta['shared'] ? '<fg=green>✓</>' : '<fg=gray>✗</>',
                $meta['lazy']   ? '<fg=cyan>✓</>'  : '<fg=gray>✗</>',
                $this->formatTags($meta['tags']),
            ]);
        }

        $table->render();

        if ($input->getOption('aliases') && !empty($this->aliases)) {
            $output->writeln('');
            $output->writeln(sprintf(
                ' <fg=white;options=bold>Aliases</> <fg=gray>(%d)</>',
                count($this->aliases),
            ));
            $output->writeln('');

            $aliasTable = new Table($output);
            $aliasTable->setStyle($tableStyle);
            $aliasTable->setHeaders(['<fg=gray>Alias</>', '<fg=gray>Target</>']);

            foreach ($this->aliases as $alias => $target) {
                $aliasTable->addRow([
                    sprintf('<fg=gray>%s</>', $this->truncate($alias, 55)),
                    sprintf('<fg=white>%s</>', $this->truncate($target, 55)),
                ]);
            }

            $aliasTable->render();
        }

        $output->writeln('');

        return Command::SUCCESS;
    }

    private function renderSingle(string $serviceId, OutputInterface $output): int
    {
        $meta = $this->services[$serviceId] ?? null;

        if ($meta === null) {
            $output->writeln(sprintf('<fg=red>Service "%s" not found.</>', $serviceId));
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln(sprintf(' <fg=white;options=bold>%s</>', $serviceId));
        $output->writeln('');
        $output->writeln(sprintf('  <fg=gray>Class   :</> %s', $meta['class']));
        $output->writeln(sprintf('  <fg=gray>Public  :</> %s', $meta['public'] ? '<fg=green>yes</>' : 'no'));
        $output->writeln(sprintf('  <fg=gray>Shared  :</> %s', $meta['shared'] ? 'yes' : 'no'));
        $output->writeln(sprintf('  <fg=gray>Lazy    :</> %s', $meta['lazy'] ? '<fg=cyan>yes</>' : 'no'));

        if (!empty($meta['tags'])) {
            $output->writeln(sprintf('  <fg=gray>Tags    :</> %s', implode(', ', $meta['tags'])));
        }

        if (!empty($meta['args'])) {
            $output->writeln('');
            $output->writeln('  <fg=gray>Arguments:</>');
            foreach ($meta['args'] as $key => $arg) {
                $output->writeln(sprintf('    <fg=gray>$%s</> → %s', $key, $arg));
            }
        }

        $output->writeln('');

        return Command::SUCCESS;
    }

    private function applyFilters(array $services, InputInterface $input): array
    {
        if ($filter = $input->getOption('filter')) {
            $filter   = strtolower($filter);
            $services = array_filter(
                $services,
                fn($meta, $id) => str_contains(strtolower($id), $filter)
                    || str_contains(strtolower($meta['class']), $filter),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        if ($tag = $input->getOption('tag')) {
            $services = array_filter(
                $services,
                fn($meta) => in_array($tag, $meta['tags'], true),
            );
        }

        return $services;
    }

    private function formatTags(array $tags): string
    {
        if (empty($tags)) {
            return '<fg=gray>—</>';
        }

        return implode(' ', array_map(
            fn($tag) => sprintf('<fg=magenta>%s</>', $tag),
            $tags,
        ));
    }

    private function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos !== false ? substr($class, $pos + 1) : $class;
    }

    private function truncate(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max - 1) . '…' : $value;
    }
}
