<?php

declare(strict_types=1);

namespace Vortos\Auth\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generates an RS256 key pair for JWT signing.
 *
 * Two output modes:
 *
 *   # file mode (dev): writes <out>/jwt_<kid>_private.pem + jwt_<kid>_public.pem
 *   php bin/console vortos:auth:keys:generate --out=/run/secrets --kid=2026-06
 *
 *   # env mode (immutable image / prod): prints base64-PEM env lines to paste into the secret store
 *   php bin/console vortos:auth:keys:generate --emit=env --kid=2026-06
 *
 * For the immutable-image / deploy-in-image path there is no writable secrets dir to generate into,
 * so env-content keys (JWT_PRIVATE_KEY / JWT_PUBLIC_KEY = base64 PEM, delivered via .env.prod) are
 * the correct posture (G8). File mode is the local/dev fallback. In file mode the output directory is
 * created if absent. Store the output outside the project root and never commit private PEMs.
 */
#[AsCommand(
    name: 'vortos:auth:keys:generate',
    description: 'Generate an RS256 key pair (PEM or base64-PEM env) for JWT signing',
)]
final class KeysGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Directory to write the PEM files to (file mode)', getcwd() ?: '.')
            ->addOption('kid', 'k', InputOption::VALUE_REQUIRED, 'Key id used in the file names', date('Y-m'))
            ->addOption('bits', 'b', InputOption::VALUE_REQUIRED, 'RSA key size in bits', '2048')
            ->addOption('emit', null, InputOption::VALUE_REQUIRED, 'Output mode: "file" (PEM files) or "env" (base64-PEM env lines)', 'file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir  = rtrim((string) $input->getOption('out'), '/');
        $kid  = (string) $input->getOption('kid');
        $bits = (int) $input->getOption('bits');
        $emit = strtolower((string) $input->getOption('emit'));

        if (!in_array($emit, ['file', 'env'], true)) {
            $output->writeln("<error>Unknown --emit mode \"{$emit}\". Use \"file\" or \"env\".</error>");
            return Command::FAILURE;
        }

        if ($bits < 2048) {
            $output->writeln('<error>RSA key size must be at least 2048 bits.</error>');
            return Command::FAILURE;
        }

        // File mode: create the output dir if absent (0700 — it holds a private key), then check it.
        if ($emit === 'file') {
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
                $output->writeln("<error>Could not create key output directory: {$dir}</error>");
                return Command::FAILURE;
            }

            if (!is_writable($dir)) {
                $output->writeln("<error>Output directory is not writable: {$dir}</error>");
                return Command::FAILURE;
            }
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

        if ($emit === 'env') {
            // Immutable-image posture (G8): emit base64-PEM env lines. The values are secrets — pipe
            // them straight into the secret store; never commit them.
            $output->writeln('<info>✔ RS256 key pair generated (env-content mode).</info>');
            $output->writeln('<comment># Add these to your secret store / .env.prod (base64-encoded PEM):</comment>');
            $output->writeln('JWT_PRIVATE_KEY=' . base64_encode((string) $privatePem));
            $output->writeln('JWT_PUBLIC_KEY=' . base64_encode($publicPem));
            $output->writeln('');
            $output->writeln("<comment>Wire it into config/auth.php (env-content first):</comment>");
            $output->writeln(sprintf(
                "  ->rs256('%s', base64_decode(\$_ENV['JWT_PRIVATE_KEY']), base64_decode(\$_ENV['JWT_PUBLIC_KEY']))",
                $kid,
            ));

            return Command::SUCCESS;
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
