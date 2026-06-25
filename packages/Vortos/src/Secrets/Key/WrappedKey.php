<?php

declare(strict_types=1);

namespace Vortos\Secrets\Key;

use InvalidArgumentException;

/**
 * A data key (DEK) sealed under a recipient's off-host identity. Not itself
 * secret-grade — without the matching private identity it is computationally
 * unrecoverable — but treated as opaque ciphertext outside {@see KeyProviderInterface}.
 */
final readonly class WrappedKey
{
    public function __construct(
        public string $ciphertext,
        public string $recipientId,
    ) {
        if ($this->ciphertext === '') {
            throw new InvalidArgumentException('WrappedKey ciphertext must not be empty.');
        }
        if ($this->recipientId === '') {
            throw new InvalidArgumentException('WrappedKey recipientId must not be empty.');
        }
    }
}
