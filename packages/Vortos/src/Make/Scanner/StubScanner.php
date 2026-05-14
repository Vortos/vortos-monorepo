<?php

declare(strict_types=1);

namespace Vortos\Make\Scanner;

use Vortos\Foundation\Module\ModulePathResolver;

final class StubScanner
{
    public function __construct(
        private readonly ModulePathResolver $resolver,
        private readonly string $projectDir,
    ) {}

    public function resolve(string $stubName): string
    {
        if (str_contains($stubName, "\0")) {
            throw new \InvalidArgumentException('Invalid stub name.');
        }

        $filename = $stubName . '.stub';
        $stubsDir = $this->projectDir . '/stubs';
        $resolved = realpath($stubsDir . '/' . $filename);
        $base     = realpath($stubsDir);

        if ($resolved !== false && $base !== false && str_starts_with($resolved, $base . DIRECTORY_SEPARATOR)) {
            return $resolved;
        }

        $paths = $this->resolver->findInModules('Resources/stubs/' . $filename);
        if (!empty($paths)) {
            return $paths[0];
        }

        throw new \RuntimeException(
            "Stub '{$stubName}' not found. Searched in:\n" .
            "  stubs/{$filename}\n" .
            "  {module}/Resources/stubs/{$filename} (all installed vortos/* packages)\n" .
            "To use a custom stub, place it at: stubs/{$filename}"
        );
    }
}
