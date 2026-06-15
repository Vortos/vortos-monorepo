<?php

declare(strict_types=1);

namespace Vortos\Auth\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates an RS256 key pair for JWT signing, written as PEM files.
 *
 *   php bin/console vortos:auth:keys:generate --out=/run/secrets --kid=2026-06
 *
 * Writes <out>/jwt_<kid>_private.pem and <out>/jwt_<kid>_public.pem. Wire them
 * into a keyring with ->rs256FromPaths('<kid>', ...) in config/auth.php.
 *
 * Store the output outside the project root and never commit private PEMs.
 */
#[AsCommand(
    name: 'vortos:auth:keys:generate',
    description: 'Generate an RS256 key pair (PEM) for JWT signing',
)]
final class KeysGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Directory to write the PEM files to', getcwd() ?: '.')
            ->addOption('kid', 'k', InputOption::VALUE_REQUIRED, 'Key id used in the file names', date('Y-m'))
            ->addOption('bits', 'b', InputOption::VALUE_REQUIRED, 'RSA key size in bits', '2048');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir  = rtrim((string) $input->getOption('out'), '/');
        $kid  = (string) $input->getOption('kid');
        $bits = (int) $input->getOption('bits');

        if (!is_dir($dir) || !is_writable($dir)) {
            $output->writeln("<error>Output directory not found or not writable: {$dir}</error>");
            return Command::FAILURE;
        }

        if ($bits < 2048) {
            $output->writeln('<error>RSA key size must be at least 2048 bits.</error>');
            return Command::FAILURE;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            $output->writeln('<error>Failed to generate RSA key pair (openssl_pkey_new).</error>');
            return Command::FAILURE;
        }

        openssl_pkey_export($resource, $privatePem);
        $details   = openssl_pkey_get_details($resource);
        $publicPem = $details['key'] ?? '';

        if ($publicPem === '') {
            $output->writeln('<error>Failed to extract the public key.</error>');
            return Command::FAILURE;
        }

        $privatePath = "{$dir}/jwt_{$kid}_private.pem";
        $publicPath  = "{$dir}/jwt_{$kid}_public.pem";

        file_put_contents($privatePath, $privatePem);
        chmod($privatePath, 0600);
        file_put_contents($publicPath, $publicPem);
        chmod($publicPath, 0644);

        $output->writeln('<info>✔ RS256 key pair generated.</info>');
        $output->writeln("  private: {$privatePath} (chmod 600)");
        $output->writeln("  public:  {$publicPath}");
        $output->writeln('');
        $output->writeln('<comment>Wire it into config/auth.php:</comment>');
        $output->writeln(sprintf(
            "  ->rs256FromPaths('%s', '%s', '%s')",
            $kid,
            $privatePath,
            $publicPath,
        ));

        return Command::SUCCESS;
    }
}
