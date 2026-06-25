<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

final readonly class EncryptionMetadata
{
    public function __construct(
        public string $provider,
        public string $recipientId,
        public int $aeadId,
    ) {}

    /** @return array{provider:string, recipient_id:string, aead_id:int} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'recipient_id' => $this->recipientId,
            'aead_id' => $this->aeadId,
        ];
    }
}
