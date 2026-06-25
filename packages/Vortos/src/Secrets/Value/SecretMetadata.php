<?php

declare(strict_types=1);

namespace Vortos\Secrets\Value;

use InvalidArgumentException;

/**
 * The version history for one secret key. Never holds plaintext.
 */
final readonly class SecretMetadata
{
    /** @param list<SecretVersion> $versions ordered oldest → newest */
    public function __construct(
        public SecretKey $key,
        public array $versions,
        public string $currentVersionId,
    ) {
        if ($versions === []) {
            throw new InvalidArgumentException('SecretMetadata must have at least one version.');
        }

        $ids = array_map(static fn (SecretVersion $v): string => $v->versionId, $versions);
        if (!in_array($currentVersionId, $ids, true)) {
            throw new InvalidArgumentException("currentVersionId '{$currentVersionId}' is not among the supplied versions.");
        }
    }

    public function current(): SecretVersion
    {
        foreach ($this->versions as $version) {
            if ($version->versionId === $this->currentVersionId) {
                return $version;
            }
        }

        // Unreachable: constructor guarantees currentVersionId is present.
        throw new InvalidArgumentException('Current version not found.');
    }

    /** @return list<SecretVersion> versions still in a valid state */
    public function validVersions(): array
    {
        return array_values(array_filter(
            $this->versions,
            static fn (SecretVersion $v): bool => $v->isValid(),
        ));
    }

    /** @return array{key: string, versions: list<array{versionId: string, createdAt: string, state: string}>, currentVersionId: string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key->value(),
            'versions' => array_map(static fn (SecretVersion $v): array => $v->toArray(), $this->versions),
            'currentVersionId' => $this->currentVersionId,
        ];
    }
}
