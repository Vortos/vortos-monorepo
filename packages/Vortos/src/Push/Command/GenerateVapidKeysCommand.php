<?php

declare(strict_types=1);

namespace Vortos\Push\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Push\Support\Base64Url;

/**
 * Generates a VAPID EC (P-256) keypair for Web Push and prints the three env
 * values to set: the base64url public key (also handed to the browser), the PEM
 * private key, and a subject placeholder.
 */
#[AsCommand(
    name: 'vortos:push:vapid:generate',
    description: 'Generate a VAPID (P-256) keypair for Web Push',
)]
final class GenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        if ($key === false) {
            $io->error('Failed to generate EC key (is ext-openssl available?).');

            return Command::FAILURE;
        }

        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);

        $publicPoint = "\x04"
            . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $publicKey = Base64Url::encode($publicPoint);

        $io->success('VAPID keypair generated. Set these in your environment:');
        $io->writeln('VAPID_PUBLIC_KEY=' . $publicKey);
        $io->newLine();
        $io->writeln('VAPID_PRIVATE_KEY (PEM — keep secret):');
        $io->writeln(trim((string) $privatePem));
        $io->newLine();
        $io->writeln('VAPID_SUBJECT=mailto:admin@example.com   # replace with your contact');

        return Command::SUCCESS;
    }
}
