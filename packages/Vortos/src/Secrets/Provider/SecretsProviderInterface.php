<?php

declare(strict_types=1);

namespace Vortos\Secrets\Provider;

use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\Secrets\Exception\SecretNotFoundException;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Rotation\RotationResult;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretMetadata;
use Vortos\Secrets\Value\SecretValue;
use Vortos\Secrets\Value\SecretVersion;

/**
 * The swap seam for "where does a secret actually live and how is it read,
 * written, and rotated". Drivers: `env` (in-core, this block); `vault`,
 * `aws-ssm` (deferred, future blocks) — all interchangeable behind this port.
 */
interface SecretsProviderInterface extends DriverInterface
{
    /** @throws SecretNotFoundException if the key has no current valid version */
    public function get(SecretKey $key): SecretValue;

    public function put(SecretKey $key, SecretValue $value): SecretVersion;

    public function rotate(SecretKey $key, RotationPolicy $policy): RotationResult;

    /** @return list<SecretKey> known secret keys — names only, never values */
    public function list(): array;

    public function versions(SecretKey $key): SecretMetadata;
}
