<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

final readonly class FreshnessSnapshot
{
    /** @param array<string, \DateTimeImmutable> $seenNonces nonce => issuedAt */
    public function __construct(
        public string $env,
        public int $lastAppliedVersion,
        public array $seenNonces = [],
    ) {
        if ($env === '') {
            throw new \InvalidArgumentException('FreshnessSnapshot env must not be empty.');
        }

        if ($lastAppliedVersion < 0) {
            throw new \InvalidArgumentException(sprintf(
                'FreshnessSnapshot lastAppliedVersion must be >= 0, got %d.',
                $lastAppliedVersion,
            ));
        }
    }

    public static function empty(string $env): self
    {
        return new self($env, 0);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $nonces = [];
        foreach ($this->seenNonces as $nonce => $issuedAt) {
            $nonces[$nonce] = $issuedAt->format(\DateTimeInterface::ATOM);
        }

        return [
            'env' => $this->env,
            'last_applied_version' => $this->lastAppliedVersion,
            'seen_nonces' => $nonces,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $nonces = [];
        /** @var array<string, string> $rawNonces */
        $rawNonces = (array) ($data['seen_nonces'] ?? []);
        foreach ($rawNonces as $nonce => $issuedAt) {
            $nonces[(string) $nonce] = new \DateTimeImmutable((string) $issuedAt);
        }

        return new self(
            env: (string) $data['env'],
            lastAppliedVersion: (int) $data['last_applied_version'],
            seenNonces: $nonces,
        );
    }
}
