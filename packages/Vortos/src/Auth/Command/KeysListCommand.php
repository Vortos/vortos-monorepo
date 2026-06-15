<?php

declare(strict_types=1);

namespace Vortos\Auth\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Auth\Jwt\JwtConfig;
use Vortos\Auth\Jwt\Key\KeyStatus;

/**
 * Lists the JWT signing keys in the configured keyring.
 *
 *   php bin/console vortos:auth:keys:list
 *
 * Shows each key's kid, algorithm and lifecycle status, and flags the single
 * Active signer. Use this to verify a rotation is staged correctly before and
 * after promoting a new key. See the rotation runbook in config/auth.php.
 */
#[AsCommand(
    name: 'vortos:auth:keys:list',
    description: 'List the JWT signing keys in the configured keyring',
)]
final class KeysListCommand extends Command
{
    public function __construct(private readonly JwtConfig $config)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $keyring = $this->config->keyring;

        $output->writeln(sprintf(
            '<info>JWT keyring</info> — algorithm <comment>%s</comment>, %d key(s):',
            $keyring->algorithm(),
            count($keyring->keys),
        ));
        $output->writeln('');

        foreach ($keyring->keys as $key) {
            $marker = $key->status === KeyStatus::Active ? '<info>●</info>' : ' ';
            $output->writeln(sprintf(
                '  %s  %-24s %-7s %s',
                $marker,
                $key->kid,
                $key->algorithm,
                $key->status->value,
            ));
        }

        $output->writeln('');
        $output->writeln(
            $keyring->isRsa()
                ? '<comment>RS256 — public keys can be published at /.well-known/jwks.json (enable with ->jwks(true)).</comment>'
                : '<comment>HS256 — shared secret; JWKS publication is not applicable.</comment>'
        );

        return Command::SUCCESS;
    }
}
