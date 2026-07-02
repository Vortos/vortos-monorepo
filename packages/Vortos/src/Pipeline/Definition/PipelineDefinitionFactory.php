<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Definition;

use Vortos\Pipeline\Model\BuildMode;
use Vortos\Pipeline\Model\ServiceContainer;
use Vortos\Release\Manifest\Arch;

/**
 * Builds the single, canonical {@see PipelineDefinition} the generate/verify commands and the
 * emitter all consume — from environment variables plus an optional `config/pipeline.php` file.
 *
 * This is the configuration surface the framework was missing (upstream P1-1..P1-4): scalar
 * settings come from env; structured settings that env cannot express (test service containers,
 * extra test steps, environment/extension lists) come from `config/pipeline.php`, which returns
 * an associative array of overrides. The config file wins over env where both are present.
 */
final class PipelineDefinitionFactory
{
    public function __invoke(string $projectDir): PipelineDefinition
    {
        $file = $this->loadConfigFile($projectDir);

        $imageRepository = $this->str($file['image_repository'] ?? null)
            ?? $this->envStr('PIPELINE_IMAGE_REPOSITORY');

        return new PipelineDefinition(
            emitter: $this->str($file['emitter'] ?? null) ?? $this->envStr('PIPELINE_EMITTER') ?? 'github',
            phpVersion: $this->str($file['php_version'] ?? null) ?? $this->envStr('PIPELINE_PHP_VERSION') ?? '8.5',
            nodeVersion: $this->str($file['node_version'] ?? null) ?? $this->envStr('PIPELINE_NODE_VERSION'),
            phpExtensions: $this->strList($file['php_extensions'] ?? null) ?? $this->envList('PIPELINE_PHP_EXTENSIONS') ?? ['redis'],
            environments: $this->strList($file['environments'] ?? null) ?? $this->envList('PIPELINE_ENVIRONMENTS') ?? ['production'],
            benchmark: $this->bool($file['benchmark'] ?? null) ?? $this->envBool('PIPELINE_BENCHMARK'),
            uiBuild: $this->bool($file['ui_build'] ?? null) ?? $this->envBool('PIPELINE_UI_BUILD'),
            uiBuildPath: $this->str($file['ui_build_path'] ?? null) ?? $this->envStr('PIPELINE_UI_BUILD_PATH'),
            imageRepository: $imageRepository,
            targetArch: $this->arch($this->str($file['target_arch'] ?? null) ?? $this->envStr('PIPELINE_TARGET_ARCH')),
            buildMode: $this->buildMode($this->str($file['build_mode'] ?? null) ?? $this->envStr('PIPELINE_BUILD_MODE')),
            baseImageDigest: $this->str($file['base_image_digest'] ?? null) ?? $this->envStr('PIPELINE_BASE_IMAGE_DIGEST'),
            emitSbom: $this->bool($file['emit_sbom'] ?? null) ?? ($this->envBool('PIPELINE_EMIT_SBOM', true)),
            dockerfilePath: $this->str($file['dockerfile_path'] ?? null) ?? $this->envStr('PIPELINE_DOCKERFILE_PATH') ?? 'Dockerfile',
            emitScanGate: $this->bool($file['emit_scan_gate'] ?? null) ?? $this->envBool('PIPELINE_EMIT_SCAN_GATE'),
            emitSign: $this->bool($file['emit_sign'] ?? null) ?? $this->envBool('PIPELINE_EMIT_SIGN'),
            registryProvider: $this->str($file['registry_provider'] ?? null) ?? $this->envStr('PIPELINE_REGISTRY_PROVIDER') ?? 'ghcr',
            workflowFilename: $this->str($file['workflow_filename'] ?? null) ?? $this->envStr('PIPELINE_WORKFLOW_FILENAME') ?? 'ci.yml',
            workflowName: $this->str($file['workflow_name'] ?? null) ?? $this->envStr('PIPELINE_WORKFLOW_NAME'),
            testCommand: $this->str($file['test_command'] ?? null) ?? $this->envStr('PIPELINE_TEST_COMMAND') ?? './vendor/bin/phpunit --testdox',
            analyseCommand: $this->str($file['analyse_command'] ?? null) ?? $this->envStr('PIPELINE_ANALYSE_COMMAND') ?? './vendor/bin/phpstan analyse',
            testServiceContainers: $this->serviceContainers($file['test_service_containers'] ?? []),
            testSteps: $this->testSteps($file['test_steps'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfigFile(string $projectDir): array
    {
        $path = rtrim($projectDir, '/') . '/config/pipeline.php';
        if ($projectDir === '' || !is_file($path)) {
            return [];
        }

        /** @var mixed $data */
        $data = require $path;

        return is_array($data) ? $data : [];
    }

    /**
     * @param mixed $raw
     * @return list<ServiceContainer>
     */
    private function serviceContainers($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if ($entry instanceof ServiceContainer) {
                $out[] = $entry;
            } elseif (is_array($entry) && isset($entry['name'], $entry['image'])) {
                /** @var array{name: string, image: string, ports?: list<string>, env?: array<string,string>, options?: list<string>} $entry */
                $out[] = ServiceContainer::fromArray($entry);
            }
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<array{name: string, run: string}>
     */
    private function testSteps($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (is_array($entry) && isset($entry['name'], $entry['run']) && is_string($entry['name']) && is_string($entry['run'])) {
                $out[] = ['name' => $entry['name'], 'run' => $entry['run']];
            }
        }

        return $out;
    }

    /** @param mixed $v */
    private function str($v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /** @param mixed $v */
    private function bool($v): ?bool
    {
        return is_bool($v) ? $v : null;
    }

    /**
     * @param mixed $v
     * @return list<string>|null
     */
    private function strList($v): ?array
    {
        if (!is_array($v)) {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn ($x): string => is_string($x) ? $x : '',
            $v,
        ), static fn (string $x): bool => $x !== ''));
    }

    private function envStr(string $key): ?string
    {
        $v = $_ENV[$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }

    private function envBool(string $key, bool $default = false): bool
    {
        $v = $_ENV[$key] ?? null;
        if (!is_string($v) || $v === '') {
            return $default;
        }

        return in_array(strtolower($v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return list<string>|null
     */
    private function envList(string $key): ?array
    {
        $v = $this->envStr($key);
        if ($v === null) {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $v)), static fn (string $x): bool => $x !== ''));
    }

    private function arch(?string $v): Arch
    {
        return match ($v) {
            'amd64', 'x86_64' => Arch::Amd64,
            default => Arch::Arm64,
        };
    }

    private function buildMode(?string $v): BuildMode
    {
        foreach (BuildMode::cases() as $case) {
            if ($case->value === $v) {
                return $case;
            }
        }

        return BuildMode::Native;
    }
}
