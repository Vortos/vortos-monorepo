<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Signature;

use Vortos\Secrets\Value\SecretValue;

final readonly class Signature
{
    public function __construct(
        public SignatureScheme $scheme,
        public SecretValue $payload,
        public ?int $rekorLogIndex = null,
    ) {}

    public function __toString(): string
    {
        return sprintf('[Signature scheme=%s redacted]', $this->scheme->value);
    }

    /** @return array{scheme: string, payload: string, rekor_log_index: ?int} */
    public function toArray(): array
    {
        return [
            'scheme' => $this->scheme->value,
            'payload' => '***',
            'rekor_log_index' => $this->rekorLogIndex,
        ];
    }
}
