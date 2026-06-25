<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\PreflightCategory;
use Vortos\Deploy\Preflight\PreflightFinding;
use Vortos\Deploy\Preflight\PreflightReport;

/**
 * Contract: `PreflightReport::toJson()` conforms to the committed JSON Schema
 * (`Tests/Fixtures/preflight-report.schema.json`). Block 14's pipeline relies on
 * this shape + `schema_version` being pinned and the key set being stable.
 *
 * We validate against the schema with a focused draft-07-subset checker (the repo
 * carries no JSON-schema library); the schema file is the single committed source of
 * truth the validator and any external consumer share.
 */
final class PreflightReportContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/preflight-report.schema.json';
        $this->schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_report_with_findings_matches_schema(): void
    {
        $report = new PreflightReport('production', [
            PreflightFinding::pass('driver_set.registered', PreflightCategory::DriverSet, 'all registered'),
            PreflightFinding::fail('credential.issuable', PreflightCategory::Credential, 'cannot mint', 'oidc unreachable', 'check issuer'),
            PreflightFinding::skip('arch.aligned', PreflightCategory::Arch, 'no constraint'),
        ]);

        $data = json_decode($report->toJson(), true, 512, JSON_THROW_ON_ERROR);

        $errors = $this->validate($data, $this->schema, '$');
        $this->assertSame([], $errors, "report did not match schema:\n" . implode("\n", $errors));
    }

    public function test_empty_report_matches_schema(): void
    {
        $report = new PreflightReport('staging', []);
        $data = json_decode($report->toJson(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([], $this->validate($data, $this->schema, '$'));
    }

    public function test_schema_version_is_pinned(): void
    {
        $this->assertSame('1.0', PreflightReport::SCHEMA_VERSION);
        $this->assertSame('1.0', $this->schema['properties']['schema_version']['const']);
    }

    /**
     * Minimal draft-07-subset validator: type, required, additionalProperties,
     * enum, const, minimum, array items.
     *
     * @param mixed                $data
     * @param array<string, mixed> $schema
     * @return list<string> validation errors (empty = valid)
     */
    private function validate(mixed $data, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['const']) && $data !== $schema['const']) {
            $errors[] = "{$path}: expected const " . json_encode($schema['const']);
        }

        if (isset($schema['enum']) && !in_array($data, $schema['enum'], true)) {
            $errors[] = "{$path}: value " . json_encode($data) . ' not in enum';
        }

        if (isset($schema['type']) && !$this->matchesType($data, (string) $schema['type'])) {
            $errors[] = "{$path}: expected type {$schema['type']}, got " . get_debug_type($data);

            return $errors; // type wrong → deeper checks meaningless
        }

        if (($schema['type'] ?? null) === 'integer' && isset($schema['minimum']) && $data < $schema['minimum']) {
            $errors[] = "{$path}: {$data} below minimum {$schema['minimum']}";
        }

        if (($schema['type'] ?? null) === 'object' && is_array($data)) {
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $data)) {
                    $errors[] = "{$path}: missing required '{$key}'";
                }
            }

            $properties = $schema['properties'] ?? [];
            if (($schema['additionalProperties'] ?? true) === false) {
                foreach (array_keys($data) as $key) {
                    if (!isset($properties[$key])) {
                        $errors[] = "{$path}: unexpected property '{$key}'";
                    }
                }
            }

            foreach ($properties as $key => $propSchema) {
                if (array_key_exists($key, $data)) {
                    $errors = [...$errors, ...$this->validate($data[$key], $propSchema, "{$path}.{$key}")];
                }
            }
        }

        if (($schema['type'] ?? null) === 'array' && is_array($data) && isset($schema['items'])) {
            foreach ($data as $i => $item) {
                $errors = [...$errors, ...$this->validate($item, $schema['items'], "{$path}[{$i}]")];
            }
        }

        return $errors;
    }

    private function matchesType(mixed $data, string $type): bool
    {
        return match ($type) {
            'object' => is_array($data) && (array_is_list($data) === false || $data === []),
            'array' => is_array($data) && (array_is_list($data) || $data === []),
            'string' => is_string($data),
            'integer' => is_int($data),
            'boolean' => is_bool($data),
            default => true,
        };
    }
}
