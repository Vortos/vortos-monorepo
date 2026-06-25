<?php
declare(strict_types=1);

namespace Vortos\Auth\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Auth\TokenFreshness\MinIatStoreInterface;

#[AsCommand(
    name: 'vortos:auth:revoke-all-tokens',
    description: 'Sets a global minimum issued-at epoch — all tokens issued before now are rejected.',
)]
final class RevokeAllTokensCommand extends Command
{
    public function __construct(private MinIatStoreInterface $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $epoch = time();
        $this->store->set($epoch);

        $output->writeln(sprintf(
            '<info>Global min_iat set to %d (%s). All tokens issued before this are now rejected.</info>',
            $epoch,
            date('Y-m-d H:i:s T', $epoch),
        ));

        return Command::SUCCESS;
    }
}
