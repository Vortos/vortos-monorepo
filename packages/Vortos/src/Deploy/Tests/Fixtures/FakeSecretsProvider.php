<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Exception\SecretNotFoundException;
use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Rotation\RotationResult;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretMetadata;
use Vortos\Secrets\Value\SecretValue;
use Vortos\Secrets\Value\SecretVersion;
use Vortos\Secrets\Value\SecretVersionState;

final class FakeSecretsProvider implements SecretsProviderInterface
{
    /** @var array<string, SecretValue> */
    private array $secrets = [];

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([]);
    }

    public function get(SecretKey $key): SecretValue
    {
        $k = $key->value();
        if (!isset($this->secrets[$k])) {
            throw SecretNotFoundException::forKey($key);
        }

        return $this->secrets[$k];
    }

    public function put(SecretKey $key, SecretValue $value): SecretVersion
    {
        $this->secrets[$key->value()] = $value;

        return new SecretVersion('1', new \DateTimeImmutable(), SecretVersionState::Active);
    }

    public function rotate(SecretKey $key, RotationPolicy $policy): RotationResult
    {
        throw new \LogicException('Not implemented in fake.');
    }

    /** @return list<SecretKey> */
    public function list(): array
    {
        return array_map(
            static fn (string $k) => SecretKey::fromString($k),
            array_keys($this->secrets),
        );
    }

    public function versions(SecretKey $key): SecretMetadata
    {
        throw new \LogicException('Not implemented in fake.');
    }

    public function setSecret(string $name, string $value): void
    {
        $this->secrets[$name] = SecretValue::fromString($value);
    }
}
