<?php

declare(strict_types=1);

namespace Vortos\Make\Engine;

use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Scanner\StubScanner;

final class GeneratorEngine
{
    public function __construct(
        private readonly StubScanner $scanner,
        private readonly string $projectDir,
    ) {}

    /** @param array<string, string> $vars */
    public function render(string $stubName, array $vars): string
    {
        $template = file_get_contents($this->scanner->resolve($stubName));

        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        return $template;
    }

    /**
     * Searches src/ for files named {$shortName}.php and returns their FQCNs.
     * Returns an empty array when none are found.
     *
     * @return list<string>
     */
    public function findClassByShortName(string $shortName): array
    {
        $srcDir   = $this->projectDir . '/src';
        $filename = $shortName . '.php';
        $found    = [];

        if (!is_dir($srcDir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getFilename() !== $filename) {
                continue;
            }

            $fqcn = $this->extractFqcn($file->getPathname());
            if ($fqcn !== null) {
                $found[] = $fqcn;
            }
        }

        return $found;
    }

    private function extractFqcn(string $filePath): ?string
    {
        $content = (string) file_get_contents($filePath);

        if (!preg_match('/^namespace\s+([^;]+);/m', $content, $ns)) {
            return null;
        }

        if (!preg_match('/^\s*(?:(?:final|readonly|abstract)\s+)*class\s+(\w+)/m', $content, $cls)) {
            return null;
        }

        return trim($ns[1]) . '\\' . trim($cls[1]);
    }

    public function ensureDirectory(string $relativeDir, OutputInterface $output): void
    {
        $fullPath = $this->projectDir . '/src/' . $relativeDir;

        if (is_dir($fullPath)) {
            $output->writeln(sprintf('  <comment>exists:</comment>  src/%s', $relativeDir));
            return;
        }

        mkdir($fullPath, 0755, true);
        $output->writeln(sprintf('  <info>created:</info> src/%s', $relativeDir));
    }

    public function write(string $relativePath, string $content, OutputInterface $output): void
    {
        $fullPath = $this->projectDir . '/src/' . $relativePath;
        $dir      = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($fullPath)) {
            $output->writeln(sprintf('  <comment>skipped (exists):</comment> src/%s', $relativePath));
            return;
        }

        file_put_contents($fullPath, $content);
        $output->writeln(sprintf('  <info>created:</info> src/%s', $relativePath));
    }
}
