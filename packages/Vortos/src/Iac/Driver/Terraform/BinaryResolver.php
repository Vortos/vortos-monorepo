<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform;

use Vortos\Iac\Exception\IacBinaryNotFoundException;

final class BinaryResolver
{
    private ?string $resolvedPath = null;
    private ?string $resolvedVersion = null;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
    ) {}

    public function resolve(?string $hint = null): string
    {
        if ($this->resolvedPath !== null) {
            return $this->resolvedPath;
        }

        $candidates = $hint !== null ? [$hint] : ['tofu', 'terraform'];

        foreach ($candidates as $binary) {
            $path = $this->which($binary);
            if ($path !== null) {
                $this->resolvedPath = $path;
                $this->resolvedVersion = $this->detectVersion($path);
                return $path;
            }
        }

        throw IacBinaryNotFoundException::noneFound();
    }

    public function version(?string $hint = null): string
    {
        $this->resolve($hint);
        return $this->resolvedVersion ?? 'unknown';
    }

    public function binaryName(?string $hint = null): string
    {
        return basename($this->resolve($hint));
    }

    private function which(string $binary): ?string
    {
        $outcome = $this->runner->run(
            ['which', $binary],
            '/',
            $this->minimalEnv(),
            5,
        );

        if ($outcome->isSuccess() && trim($outcome->stdout) !== '') {
            return trim($outcome->stdout);
        }

        return null;
    }

    private function detectVersion(string $binaryPath): string
    {
        $outcome = $this->runner->run(
            [$binaryPath, 'version', '-json'],
            '/',
            $this->minimalEnv(),
            5,
        );

        if ($outcome->isSuccess()) {
            $decoded = json_decode($outcome->stdout, true);
            if (is_array($decoded) && isset($decoded['terraform_version'])) {
                return (string) $decoded['terraform_version'];
            }
        }

        $outcome = $this->runner->run(
            [$binaryPath, '--version'],
            '/',
            $this->minimalEnv(),
            5,
        );

        if ($outcome->isSuccess()) {
            if (preg_match('/v?([\d.]+)/', $outcome->stdout, $m)) {
                return $m[1];
            }
        }

        return 'unknown';
    }

    /** @return array<string, string> */
    private function minimalEnv(): array
    {
        return ['PATH' => '/usr/local/bin:/usr/bin:/bin'];
    }
}
