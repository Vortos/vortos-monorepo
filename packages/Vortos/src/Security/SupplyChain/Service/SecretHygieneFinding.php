<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Service;

final readonly class SecretHygieneFinding
{
    public function __construct(
        public string $secretId,
        public string $kind,
        public string $detail,
    ) {}

    /** @return array{secret_id: string, kind: string, detail: string} */
    public function toArray(): array
    {
        return [
            'secret_id' => $this->secretId,
            'kind' => $this->kind,
            'detail' => $this->detail,
        ];
    }
}
