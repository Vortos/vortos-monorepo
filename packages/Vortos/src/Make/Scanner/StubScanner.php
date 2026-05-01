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
        $filename = $stubName . '.stub';

        $userStub = $this->projectDir . '/stubs/' . $filename;
        if (file_exists($userStub)) {
            return $userStub;
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
