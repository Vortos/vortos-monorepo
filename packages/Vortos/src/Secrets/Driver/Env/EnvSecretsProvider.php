<?php

declare(strict_types=1);

namespace Vortos\Secrets\Driver\Env;

use DateTimeImmutable;
use RuntimeException;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Secrets\Crypto\EnvelopeCipher;
use Vortos\Secrets\Crypto\SecretEnvelope;
use Vortos\Secrets\Exception\SecretNotFoundException;
use Vortos\Secrets\Key\DataKey;
use Vortos\Secrets\Key\KeyProviderInterface;
use Vortos\Secrets\Key\WrappedKey;
use Vortos\Secrets\Provider\SecretsCapability;
use Vortos\Secrets\Provider\SecretsProviderInterface;
use Vortos\Secrets\Rotation\RotationPolicy;
use Vortos\Secrets\Rotation\RotationResult;
use Vortos\Secrets\Store\SecretStoreInterface;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretMetadata;
use Vortos\Secrets\Value\SecretValue;
use Vortos\Secrets\Value\SecretVersion;
use Vortos\Secrets\Value\SecretVersionState;

/**
 * The default, in-core secrets provider for one deployment environment: every
 * secret for that environment lives, encrypted, in a single
 * {@see SecretEnvelope} read/written via {@see SecretStoreInterface} (default:
 * {@see \Vortos\Secrets\Driver\File\FileSecretStore}). The DEK is wrapped/unwrapped
 * via {@see KeyProviderInterface} (default: `age`'s off-host X25519 identity) —
 * this driver never touches identity material directly.
 *
 * The decrypted document is memoized **in memory, once per process** — `open()`
 * happens at most once, every `get()` thereafter is O(1). The decrypted plaintext
 * never touches disk; every value is immediately wrapped in a
 * {@see SecretValue} so it is redacted-by-construction the moment it exists.
 *
 * `rotate()` mints a fresh random value (32 bytes) for secrets that are
 * themselves rotated tokens (e.g. internal signing/API keys) — a secret whose new
 * value is issued externally (e.g. a third-party API key) should be re-`put()`,
 * not `rotate()`d.
 */
#[AsDriver('env')]
final class EnvSecretsProvider implements SecretsProviderInterface
{
    private const WRAPPED_DEK_RECIPIENT = 'default';

    /** @var array<string, SecretMetadata>|null */
    private ?array $metadataByKey = null;

    /** @var array<string, array<string, SecretValue>> keyed by [secret key value][versionId] */
    private array $valuesByKey = [];

    public function __construct(
        private readonly SecretStoreInterface $store,
        private readonly KeyProviderInterface $keyProvider,
        private readonly EnvelopeCipher $cipher,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SecretsCapability::Versioning->value => true,
            SecretsCapability::Rotation->value => true,
            SecretsCapability::TwoPhaseRotation->value => true,
            SecretsCapability::OffHostKey->value => true,
            SecretsCapability::Put->value => true,
            SecretsCapability::ListKeys->value => true,
            SecretsCapability::InMemoryOnly->value => false,
        ]);
    }

    public function get(SecretKey $key): SecretValue
    {
        $this->ensureLoaded();

        $metadata = $this->metadataByKey[$key->value()] ?? null;
        if ($metadata === null) {
            throw SecretNotFoundException::forKey($key);
        }

        return $this->valuesByKey[$key->value()][$metadata->currentVersionId];
    }

    public function put(SecretKey $key, SecretValue $value): SecretVersion
    {
        $this->ensureLoaded();

        $now = new DateTimeImmutable();
        $version = new SecretVersion(self::newVersionId(), $now, SecretVersionState::Active);

        $existing = $this->metadataByKey[$key->value()] ?? null;
        $versions = $existing !== null ? [...$existing->versions, $version] : [$version];

        $this->metadataByKey[$key->value()] = new SecretMetadata($key, $versions, $version->versionId);
        $this->valuesByKey[$key->value()][$version->versionId] = $value;

        $this->persist();

        return $version;
    }

    public function rotate(SecretKey $key, RotationPolicy $policy): RotationResult
    {
        $this->ensureLoaded();

        $metadata = $this->metadataByKey[$key->value()] ?? null;
        if ($metadata === null) {
            throw SecretNotFoundException::forKey($key);
        }

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

        $this->persist();

        $graceExpiresAt = $now->modify(sprintf('+%d seconds', $policy->gracePeriodSeconds));

        return new RotationResult($newVersion, $supersededVersion, $graceExpiresAt);
    }

    /** @return list<SecretKey> */
    public function list(): array
    {
        $this->ensureLoaded();

        return array_values(array_map(
            static fn (SecretMetadata $metadata): SecretKey => $metadata->key,
            $this->metadataByKey,
        ));
    }

    public function versions(SecretKey $key): SecretMetadata
    {
        $this->ensureLoaded();

        return $this->metadataByKey[$key->value()] ?? throw SecretNotFoundException::forKey($key);
    }

    private function ensureLoaded(): void
    {
        if ($this->metadataByKey !== null) {
            return;
        }

        if (!$this->store->exists()) {
            $this->metadataByKey = [];

            return;
        }

        $envelope = $this->store->load();
        $dataKey = $this->keyProvider->unwrap($this->wrappedDekFrom($envelope));

        try {
            $plaintext = $this->cipher->decryptPayload($envelope->aeadCiphertext, $envelope->nonce, $dataKey->revealForEncryption());
        } finally {
            $dataKey->wipe();
        }

        /** @var array<string, array{versions: list<array{versionId: string, createdAt: string, state: string}>, currentVersionId: string, values: array<string, string>}> $document */
        $document = json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);

        $metadataByKey = [];
        $valuesByKey = [];
        foreach ($document as $keyValue => $entry) {
            $secretKey = SecretKey::fromString($keyValue);
            $versions = array_map(SecretVersion::fromArray(...), $entry['versions']);
            $metadataByKey[$keyValue] = new SecretMetadata($secretKey, $versions, $entry['currentVersionId']);

            foreach ($entry['values'] as $versionId => $base64Value) {
                $decoded = base64_decode($base64Value, true);
                if ($decoded === false) {
                    throw new RuntimeException("Secret store value for '{$keyValue}'/'{$versionId}' is not valid base64.");
                }
                $valuesByKey[$keyValue][$versionId] = SecretValue::fromString($decoded);
            }
        }

        $this->metadataByKey = $metadataByKey;
        $this->valuesByKey = $valuesByKey;
    }

    private function persist(): void
    {
        $document = [];
        foreach ($this->metadataByKey as $keyValue => $metadata) {
            $values = [];
            foreach ($this->valuesByKey[$keyValue] as $versionId => $value) {
                $values[$versionId] = base64_encode($value->reveal());
            }

            $document[$keyValue] = [
                'versions' => array_map(static fn (SecretVersion $v): array => $v->toArray(), $metadata->versions),
                'currentVersionId' => $metadata->currentVersionId,
                'values' => $values,
            ];
        }

        $plaintext = json_encode($document, JSON_THROW_ON_ERROR);

        $dataKey = DataKey::fromRaw($this->cipher->generateDataKey());
        try {
            $payload = $this->cipher->encryptPayload($plaintext, $dataKey->revealForEncryption());
            $wrapped = $this->keyProvider->wrap($dataKey);
        } finally {
            $dataKey->wipe();
        }

        $envelope = new SecretEnvelope(
            1,
            $payload['ciphertext'],
            $payload['nonce'],
            EnvelopeCipher::AAD,
            [$wrapped->recipientId => $wrapped->ciphertext],
            new DateTimeImmutable(),
        );

        $this->store->save($envelope);
    }

    private function wrappedDekFrom(SecretEnvelope $envelope): WrappedKey
    {
        $ciphertext = $envelope->wrappedDeks[self::WRAPPED_DEK_RECIPIENT]
            ?? throw new RuntimeException("Secret store envelope has no wrapped DEK for recipient '" . self::WRAPPED_DEK_RECIPIENT . "'.");

        return new WrappedKey($ciphertext, self::WRAPPED_DEK_RECIPIENT);
    }

    private static function newVersionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
