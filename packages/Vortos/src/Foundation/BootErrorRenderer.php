<?php

declare(strict_types=1);

namespace Vortos\Foundation;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders boot exceptions in console context with structured hints.
 *
 * Catches common patterns (missing DefaultImpl binding, unresolvable services,
 * missing env vars) and appends actionable fix hints below the error block.
 */
final class BootErrorRenderer
{
    private const HINTS = [
        'has been excluded'              => "Add #[DefaultImpl] to the implementation class, or register the binding manually in config/services.php.",
        'VORTOS_REPLAY_SECRET'           => "Set VORTOS_REPLAY_SECRET in your .env file. Run: php bin/console vortos:doctor for a full diagnostic.",
        'Cannot autowire service'        => "The service cannot be autowired. Check that all constructor dependencies are registered in the container or add #[DefaultImpl] to the implementation.",
        'Service \"vortos'               => "A Vortos service failed to initialize. Run: php bin/console vortos:doctor to identify the root cause.",
        'Extension \"vortos_'            => "A Vortos extension failed to load. Check your module configuration in config/packages/.",
        'There is no extension able'     => "An unknown extension key was used in configuration. Check your config/packages/ files for typos.",
        'environment variable'           => "A required environment variable is not set. Run: php bin/console vortos:doctor to see which variables are missing.",
    ];

    public function render(\Throwable $e, OutputInterface $output): void
    {
        $class   = get_class($e);
        $message = $e->getMessage();
        $file    = $e->getFile();
        $line    = $e->getLine();

        $output->writeln('');
        $output->writeln('<error> BOOT ERROR </error>');
        $output->writeln('');
        $output->writeln(sprintf('  <fg=red>%s</>', $class));
        $output->writeln('');

        foreach (explode("\n", wordwrap($message, 80, "\n", true)) as $messageLine) {
            $output->writeln(sprintf('  %s', $messageLine));
        }

        $output->writeln('');
        $output->writeln(sprintf('  <fg=gray>%s:%d</>', $file, $line));
        $output->writeln('');

        $hint = $this->findHint($message);

        if ($hint !== null) {
            $output->writeln('  <comment>Hint:</comment>');

            foreach (explode("\n", wordwrap($hint, 76, "\n", true)) as $hintLine) {
                $output->writeln(sprintf('  %s', $hintLine));
            }

            $output->writeln('');
        }
    }

    private function findHint(string $message): ?string
    {
        foreach (self::HINTS as $pattern => $hint) {
            if (str_contains($message, $pattern)) {
                return $hint;
            }
        }

        return null;
    }
}
