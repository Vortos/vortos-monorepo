<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Fixtures;

use DateTimeImmutable;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Exception\SecretNotFoundException;
use Vortos\Secrets\Provider\SecretsCapability;
use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Rotation\RotationResult;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretMetadata;
use Vortos\Secrets\Value\SecretValue;
use Vortos\Secrets\Value\SecretVersion;
use Vortos\Secrets\Value\SecretVersionState;

/**
 * A pure in-memory {@see SecretsProviderInterface} fixture for fast Service-layer
 * unit tests that do not need real crypto/filesystem — that coverage lives in the
 * {@see \Vortos\Secrets\Tests\Conformance\EnvSecretsProviderConformanceTest} and
 * the Driver integration tests.
 */
#[AsDriver('in-memory')]
final class InMemorySecretsProvider implements SecretsProviderInterface
{
    /** @var array<string, SecretMetadata> */
    private array $metadataByKey = [];

    /** @var array<string, array<string, SecretValue>> */
    private array $valuesByKey = [];

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SecretsCapability::Versioning->value => true,
            SecretsCapability::Rotation->value => true,
            SecretsCapability::TwoPhaseRotation->value => true,
            SecretsCapability::OffHostKey->value => false,
            SecretsCapability::Put->value => true,
            SecretsCapability::ListKeys->value => true,
            SecretsCapability::InMemoryOnly->value => true,
        ]);
    }

    public function get(SecretKey $key): SecretValue
    {
        $metadata = $this->metadataByKey[$key->value()] ?? throw SecretNotFoundException::forKey($key);

        return $this->valuesByKey[$key->value()][$metadata->currentVersionId];
    }

    public function put(SecretKey $key, SecretValue $value): SecretVersion
    {
        $version = new SecretVersion(self::newVersionId(), new DateTimeImmutable(), SecretVersionState::Active);

        $existing = $this->metadataByKey[$key->value()] ?? null;
        $versions = $existing !== null ? [...$existing->versions, $version] : [$version];

        $this->metadataByKey[$key->value()] = new SecretMetadata($key, $versions, $version->versionId);
        $this->valuesByKey[$key->value()][$version->versionId] = $value;

        return $version;
    }

    public function rotate(SecretKey $key, RotationPolicy $policy): RotationResult
    {
        $metadata = $this->metadataByKey[$key->value()] ?? throw SecretNotFoundException::forKey($key);

        $now = new DateTimeImmutable();
        $supersededVersion = $metadata->current()->withState(SecretVersionState::WithinGrace);
        $newVersion = new SecretVersion(self::newVersionId(), $now, SecretVersionState::Active);
        $newValue = SecretValue::fromString(base64_encode(random_bytes(32)));

        $versions = array_map(
            static fn (SecretVersion $v): SecretVersion => $v->versionId === $supersededVersion->versionId ? $supersededVersion : $v,
            $metadata->versions,
        );
        $versions[] = $newVersion;

        $this->metadataByKey[$key->value()] = new SecretMetadata($key, $versions, $newVersion->versionId);
        $this->valuesByKey[$key->value()][$newVersion->versionId] = $newValue;

        $graceExpiresAt = $now->modify(sprintf('+%d seconds', $policy->gracePeriodSeconds));

        return new RotationResult($newVersion, $supersededVersion, $graceExpiresAt);
    }

    /** @return list<SecretKey> */
    public function list(): array
    {
        return array_values(array_map(
            static fn (SecretMetadata $metadata): SecretKey => $metadata->key,
            $this->metadataByKey,
        ));
    }

    public function versions(SecretKey $key): SecretMetadata
    {
        return $this->metadataByKey[$key->value()] ?? throw SecretNotFoundException::forKey($key);
    }

    private static function newVersionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
