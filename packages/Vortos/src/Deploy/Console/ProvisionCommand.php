<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Deploy\Provision\FirstDeployProvisioner;
use Vortos\Deploy\Provision\JwtKeyPresence;

/**
 * First-deploy provisioning (G4): idempotently ensures a fresh box can boot the app — RS256 JWT
 * keys, an up-to-date schema, and a satisfied secrets gate — before deploy:doctor and deploy
 * run. Executed on the VPS as part of the deploy-on-target flow (it appears in the generated remote
 * script). Fail-closed: a non-zero from any step aborts and is surfaced.
 */
#[AsCommand(
    name: 'vortos:deploy:provision',
    description: 'Idempotent first-deploy provisioning: JWT keys, migrations, secrets preflight.',
)]
final class ProvisionCommand extends Command
{
    public function __construct(
        private readonly FirstDeployProvisioner $provisioner,
        private readonly JwtKeyPresence $jwtKeys = new JwtKeyPresence(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment name', 'production')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $json = (bool) $input->getOption('json');

        $keyOutputDir = $this->jwtKeys->keyOutputDir();
        $keysPresent = $this->jwtKeys->present();

        $steps = $this->provisioner->plan($keysPresent, $keyOutputDir, $env);
        $application = $this->getApplication();

        if ($application === null) {
            $output->writeln('<error>Provisioning requires a console Application.</error>');

            return self::FAILURE;
        }

        $ran = [];
        foreach ($steps as $step) {
            $command = $application->find($step->command);
            $argv = ['command' => $step->command];
            foreach ($step->args as $arg) {
                [$name, $value] = array_pad(explode('=', $arg, 2), 2, null);
                $argv[$name] = $value ?? true;
            }

            $code = $command->run(new ArrayInput($argv), $output);
            $ran[] = ['step' => $step->command, 'description' => $step->description, 'exit' => $code];

            if ($code !== self::SUCCESS) {
                if ($json) {
                    $output->writeln((string) json_encode(['ok' => false, 'steps' => $ran], JSON_THROW_ON_ERROR));
                } else {
                    $output->writeln(sprintf('<error>Provisioning aborted at "%s" (exit %d).</error>', $step->command, $code));
                }

                return self::FAILURE;
            }
        }

        if ($json) {
            $output->writeln((string) json_encode(['ok' => true, 'keys_present' => $keysPresent, 'steps' => $ran], JSON_THROW_ON_ERROR));
        } else {
            $output->writeln('<info>First-deploy provisioning complete.</info>');
        }

        return self::SUCCESS;
    }
}
