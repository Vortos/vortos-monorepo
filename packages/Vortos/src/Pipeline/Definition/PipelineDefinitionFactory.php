<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Definition;

use Vortos\Pipeline\Model\BuildMode;
use Vortos\Pipeline\Model\ServiceContainer;
use Vortos\Pipeline\Model\SplitPackage;
use Vortos\Release\Manifest\Arch;

/**
 * Builds the single, canonical {@see PipelineDefinition} the generate/verify commands and the
 * emitter all consume — from environment variables plus an optional `config/pipeline.php` file.
 *
 * `config/pipeline.php` may return either:
 *   - a {@see PipelineDefinition} — used verbatim (fully resolved; no env overlay);
 *   - a {@see PipelineDefinitionBuilder} — `build()`ed verbatim (no env overlay); or
 *   - an associative array of overrides — env fills the gaps, file wins where both are present.
 *
 * The array path routes every value through {@see PipelineDefinitionBuilder} so defaults live in
 * exactly one place (the builder) rather than being duplicated here.
 */
final class PipelineDefinitionFactory
{
    public function __invoke(string $projectDir): PipelineDefinition
    {
        $config = $this->loadConfig($projectDir);

        // A typed config return is authoritative — the app opted into the fluent/explicit path,
        // so env does not overlay it.
        if ($config instanceof PipelineDefinition) {
            return $config;
        }

        if ($config instanceof PipelineDefinitionBuilder) {
            return $config->build();
        }

        return $this->fromArray($config);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function fromArray(array $file): PipelineDefinition
    {
        $b = PipelineDefinitionBuilder::create();

        if (($v = $this->strOrEnv($file, 'emitter', 'PIPELINE_EMITTER')) !== null) {
            $b = $b->emitter($v);
        }
        if (($v = $this->strOrEnv($file, 'php_version', 'PIPELINE_PHP_VERSION')) !== null) {
            $b = $b->phpVersion($v);
        }
        if (($v = $this->strOrEnv($file, 'node_version', 'PIPELINE_NODE_VERSION')) !== null) {
            $b = $b->nodeVersion($v);
        }
        if (($v = $this->listOrEnv($file, 'php_extensions', 'PIPELINE_PHP_EXTENSIONS')) !== null) {
            $b = $b->phpExtensions($v);
        }
        if (($v = $this->listOrEnv($file, 'environments', 'PIPELINE_ENVIRONMENTS')) !== null) {
            $b = $b->environments($v);
        }
        if (($v = $this->boolOrEnv($file, 'benchmark', 'PIPELINE_BENCHMARK')) !== null) {
            $b = $b->benchmark($v);
        }

        $uiBuild     = $this->boolOrEnv($file, 'ui_build', 'PIPELINE_UI_BUILD');
        $uiBuildPath = $this->strOrEnv($file, 'ui_build_path', 'PIPELINE_UI_BUILD_PATH');
        if ($uiBuild !== null || $uiBuildPath !== null) {
            $b = $b->uiBuild($uiBuild ?? false, $uiBuildPath);
        }

        $split = $this->splitPackages($file['split_packages'] ?? null);
        if ($split !== []) {
            $b = $b->splitPackages($split);
        }

        if (($v = $this->intOrEnv($file, 'default_timeout_minutes', 'PIPELINE_DEFAULT_TIMEOUT_MINUTES')) !== null) {
            $b = $b->defaultTimeoutMinutes($v);
        }
        if (($v = $this->strOrEnv($file, 'target_arch', 'PIPELINE_TARGET_ARCH')) !== null) {
            $b = $b->targetArch($this->arch($v));
        }
        if (($v = $this->strOrEnv($file, 'image_repository', 'PIPELINE_IMAGE_REPOSITORY')) !== null) {
            $b = $b->imageRepository($v);
        }
        if (($v = $this->strOrEnv($file, 'build_mode', 'PIPELINE_BUILD_MODE')) !== null) {
            $b = $b->buildMode($this->buildMode($v));
        }
        if (($v = $this->strOrEnv($file, 'native_runner_label', 'PIPELINE_NATIVE_RUNNER_LABEL')) !== null) {
            $b = $b->nativeRunnerLabel($v);
        }
        if (($v = $this->boolOrEnv($file, 'oidc', 'PIPELINE_OIDC')) !== null) {
            $b = $b->oidc($v);
        }
        if (($v = $this->strOrEnv($file, 'base_image_digest', 'PIPELINE_BASE_IMAGE_DIGEST')) !== null) {
            $b = $b->baseImageDigest($v);
        }
        if (($v = $this->boolOrEnv($file, 'emit_sbom', 'PIPELINE_EMIT_SBOM')) !== null) {
            $b = $b->emitSbom($v);
        }
        if (($v = $this->strOrEnv($file, 'dockerfile_path', 'PIPELINE_DOCKERFILE_PATH')) !== null) {
            $b = $b->dockerfilePath($v);
        }
        if (($v = $this->boolOrEnv($file, 'emit_scan_gate', 'PIPELINE_EMIT_SCAN_GATE')) !== null) {
            $b = $b->emitScanGate($v);
        }
        if (($v = $this->boolOrEnv($file, 'emit_sign', 'PIPELINE_EMIT_SIGN')) !== null) {
            $b = $b->emitSign($v);
        }
        if (($v = $this->strOrEnv($file, 'registry_provider', 'PIPELINE_REGISTRY_PROVIDER')) !== null) {
            $b = $b->registryProvider($v);
        }
        if (($v = $this->strOrEnv($file, 'workflow_filename', 'PIPELINE_WORKFLOW_FILENAME')) !== null) {
            $b = $b->workflowFilename($v);
        }
        if (($v = $this->strOrEnv($file, 'workflow_name', 'PIPELINE_WORKFLOW_NAME')) !== null) {
            $b = $b->workflowName($v);
        }
        if (($v = $this->strOrEnv($file, 'test_command', 'PIPELINE_TEST_COMMAND')) !== null) {
            $b = $b->testCommand($v);
        }
        if (($v = $this->strOrEnv($file, 'analyse_command', 'PIPELINE_ANALYSE_COMMAND')) !== null) {
            $b = $b->analyseCommand($v);
        }

        $containers = $this->serviceContainers($file['test_service_containers'] ?? []);
        if ($containers !== []) {
            $b = $b->testServiceContainers($containers);
        }
        $steps = $this->testSteps($file['test_steps'] ?? []);
        if ($steps !== []) {
            $b = $b->testSteps($steps);
        }

        return $b->build();
    }

    /**
     * @return array<string, mixed>|PipelineDefinition|PipelineDefinitionBuilder
     */
    private function loadConfig(string $projectDir): array|PipelineDefinition|PipelineDefinitionBuilder
    {
        $path = rtrim($projectDir, '/') . '/config/pipeline.php';
        if ($projectDir === '' || !is_file($path)) {
            return [];
        }

        /** @var mixed $data */
        $data = require $path;

        if ($data instanceof PipelineDefinition || $data instanceof PipelineDefinitionBuilder) {
            return $data;
        }

        if (is_array($data)) {
            /** @var array<string, mixed> $data */
            return $data;
        }

        return [];
    }

    /**
     * @param mixed $raw
     * @return list<SplitPackage>
     */
    private function splitPackages($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if ($entry instanceof SplitPackage) {
                $out[] = $entry;
            } elseif (
                is_array($entry)
                && isset($entry['local_path'], $entry['split_repository'])
                && is_string($entry['local_path'])
                && is_string($entry['split_repository'])
            ) {
                $out[] = new SplitPackage($entry['local_path'], $entry['split_repository']);
            }
        }

        return $out;
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

    /**
     * @param array<string, mixed> $file
     */
    private function strOrEnv(array $file, string $fileKey, string $envKey): ?string
    {
        return $this->str($file[$fileKey] ?? null) ?? $this->envStr($envKey);
    }

    /**
     * @param array<string, mixed> $file
     * @return list<string>|null
     */
    private function listOrEnv(array $file, string $fileKey, string $envKey): ?array
    {
        return $this->strList($file[$fileKey] ?? null) ?? $this->envList($envKey);
    }

    /**
     * File value wins; otherwise env presence decides. Returns null only when neither is set,
     * so the builder's own default applies.
     *
     * @param array<string, mixed> $file
     */
    private function boolOrEnv(array $file, string $fileKey, string $envKey): ?bool
    {
        $fileVal = $this->bool($file[$fileKey] ?? null);
        if ($fileVal !== null) {
            return $fileVal;
        }

        return $this->envBoolOrNull($envKey);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function intOrEnv(array $file, string $fileKey, string $envKey): ?int
    {
        $fileVal = $file[$fileKey] ?? null;
        if (is_int($fileVal)) {
            return $fileVal;
        }

        $envVal = $this->envStr($envKey);
        if ($envVal !== null && ctype_digit($envVal)) {
            return (int) $envVal;
        }

        return null;
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

    private function envBoolOrNull(string $key): ?bool
    {
        $v = $_ENV[$key] ?? null;
        if (!is_string($v) || $v === '') {
            return null;
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
