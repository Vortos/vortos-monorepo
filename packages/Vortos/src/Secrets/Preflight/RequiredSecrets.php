<?php

declare(strict_types=1);

namespace Vortos\Secrets\Preflight;

use Vortos\Secrets\Value\SecretKey;

/**
 * The declared set of secrets an environment/app needs — the input to
 * {@see \Vortos\Secrets\Service\SecretsPreflight}.
 */
final readonly class RequiredSecrets
{
    /** @param list<SecretReference> $references */
    public function __construct(public array $references) {}

    /** @return list<SecretKey> only the references marked required */
    public function requiredKeys(): array
    {
        return array_values(array_map(
            static fn (SecretReference $r): SecretKey => $r->key,
            array_filter($this->references, static fn (SecretReference $r): bool => $r->required),
        ));
    }

    /** @return list<SecretKey> every declared key, required or optional */
    public function allKeys(): array
    {
        return array_map(static fn (SecretReference $r): SecretKey => $r->key, $this->references);
    }

    public function descriptionFor(SecretKey $key): string
    {
        foreach ($this->references as $reference) {
            if ($reference->key->equals($key)) {
                return $reference->description;
            }
        }

        return '';
    }
}
