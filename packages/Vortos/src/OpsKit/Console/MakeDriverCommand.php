<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `vortos:make:driver <concern> <name>` — the guided path to a new driver (§10.5).
 *
 * Scaffolds, with zero archaeology:
 *   - the driver class (implementing the port, carrying #[AsDriver(key)], with a
 *     capabilities() skeleton) in a `Driver\<Name>` namespace (so it is automatically
 *     exempt from the agnosticism lint), and
 *   - its conformance-test skeleton extending the concern's TCK.
 *
 * With `--split` it also scaffolds a full installable package: a valid composer.json
 * (`vortos/vortos-<concern>-<name>`, with the `extra.vortos.order` in the §14.1.1
 * band and the Package class wired) plus the DI Package/Extension that tag the driver.
 */
#[AsCommand(
    name: 'vortos:make:driver',
    description: 'Scaffold a swappable-concern driver (impl + #[AsDriver] + conformance test).',
)]
final class MakeDriverCommand extends Command
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9-]*$/';

    public function __construct(private readonly DriverScaffolder $scaffolder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('concern', InputArgument::REQUIRED, 'Concern key, e.g. deploy, health, secrets')
            ->addArgument('name', InputArgument::REQUIRED, 'Driver key (lower-kebab), e.g. k8s, ssh-compose')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'FQCN of the concern port interface the driver implements')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Parent namespace for the driver (default App\\<Concern>\\Driver)')
            ->addOption('tck', null, InputOption::VALUE_REQUIRED, 'FQCN of the concern conformance TCK base')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'DI tag the concern collects (default vortos.<concern>.driver)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Output directory that will contain the driver folder')
            ->addOption('order', null, InputOption::VALUE_REQUIRED, 'extra.vortos.order for --split packages', '150')
            ->addOption('split', null, InputOption::VALUE_NONE, 'Also scaffold a full split composer package');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $concern = (string) $input->getArgument('concern');
        $name    = (string) $input->getArgument('name');

        if (preg_match(self::NAME_PATTERN, $concern) !== 1) {
            $output->writeln("<error>Invalid concern '{$concern}'. Use lower-kebab: ^[a-z][a-z0-9-]*$.</error>");
            return Command::FAILURE;
        }
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $output->writeln("<error>Invalid driver name '{$name}'. Use lower-kebab: ^[a-z][a-z0-9-]*$.</error>");
            return Command::FAILURE;
        }

        $order = $input->getOption('order');
        if (!is_string($order) || preg_match('/^\d+$/', $order) !== 1) {
            $output->writeln('<error>--order must be an integer.</error>');
            return Command::FAILURE;
        }

        $concernPascal = $this->pascal($concern);
        $pascal        = $this->pascal($name);
        $className     = $pascal . 'Driver';

        $parentNamespace = $this->normalizeOption($input->getOption('namespace'))
            ?? sprintf('App\\%s\\Driver', $concernPascal);
        $driverNamespace = $parentNamespace . '\\' . $pascal;

        $port      = $this->normalizeOption($input->getOption('port')) ?? \Vortos\OpsKit\Driver\DriverInterface::class;
        $tckBase   = $this->normalizeOption($input->getOption('tck')) ?? \Vortos\OpsKit\Testing\ConformanceTestCase::class;
        $tag       = $this->normalizeOption($input->getOption('tag')) ?? sprintf('vortos.%s.driver', $concern);
        $alias     = sprintf('vortos_%s_%s', $concern, str_replace('-', '_', $name));

        $baseDir = $this->normalizeOption($input->getOption('dir'))
            ?? $this->scaffolder->projectDir() . '/src/' . $concernPascal . '/Driver';
        $driverDir = rtrim($baseDir, '/') . '/' . $pascal;

        $vars = [
            'Concern'         => $concern,
            'Key'             => $name,
            'Pascal'          => $pascal,
            'ConcernPascal'   => $concernPascal,
            'ClassName'       => $className,
            'DriverNamespace' => $driverNamespace,
            'Port'            => ltrim($port, '\\'),
            'PortShort'       => $this->shortName($port),
            'TckBase'         => ltrim($tckBase, '\\'),
            'TckBaseShort'    => $this->shortName($tckBase),
            'Tag'             => $tag,
            'Alias'           => $alias,
            'Order'           => $order,
        ];

        $output->writeln("<info>vortos:make:driver</info> {$concern} {$name}");
        $output->writeln('');

        $written = [];
        $written[$driverDir . '/' . $className . '.php'] = $this->scaffolder->render('driver', $vars);
        $written[$driverDir . '/Tests/' . $className . 'ConformanceTest.php'] = $this->scaffolder->render('conformance-test', $vars);

        if ($input->getOption('split')) {
            $written[$driverDir . '/composer.json'] = $this->composerJson($concern, $name, $pascal, $driverNamespace, (int) $order);
            $written[$driverDir . '/DependencyInjection/' . $pascal . 'Package.php'] = $this->scaffolder->render('package', $vars);
            $written[$driverDir . '/DependencyInjection/' . $pascal . 'Extension.php'] = $this->scaffolder->render('extension', $vars);
        }

        foreach ($written as $path => $contents) {
            $result = $this->scaffolder->write($path, $contents);
            $label  = $result === 'created' ? '<info>created:</info>' : '<comment>skipped (exists):</comment>';
            $output->writeln("  {$label} {$path}");
        }

        $output->writeln('');
        $output->writeln("Next: tag the driver in the {$concern} DI extension via registerForAutoconfiguration({$vars['PortShort']}::class)->addTag('{$tag}'),");
        $output->writeln("then declare its real capabilities() and implement the port methods.");
        if ($input->getOption('split')) {
            $output->writeln("<comment>Note:</comment> confirm --order={$order} sits in this concern's §14.1.1 band (core+1 … core+9).");
        }

        return Command::SUCCESS;
    }

    private function composerJson(string $concern, string $name, string $pascal, string $driverNamespace, int $order): string
    {
        $manifest = [
            'name' => sprintf('vortos/vortos-%s-%s', $concern, $name),
            'description' => sprintf('%s driver for the Vortos %s concern.', $pascal, $concern),
            'type' => 'library',
            'license' => 'MIT',
            'autoload' => [
                'psr-4' => [
                    $driverNamespace . '\\' => '',
                ],
            ],
            'require' => [
                'php' => '>=8.2',
                'vortos/vortos-ops-kit' => '^1.0',
                sprintf('vortos/vortos-%s', $concern) => '^1.0',
            ],
            'extra' => [
                'vortos' => [
                    'package' => $driverNamespace . '\\DependencyInjection\\' . $pascal . 'Package',
                    'order' => $order,
                ],
            ],
        ];

        return (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function pascal(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $value)));
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }

    private function normalizeOption(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
