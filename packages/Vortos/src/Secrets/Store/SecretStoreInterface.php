<?php

declare(strict_types=1);

namespace Vortos\Secrets\Store;

use Vortos\Secrets\Crypto\SecretEnvelope;
use Vortos\Secrets\Exception\SecretNotFoundException;

/**
 * Where an encrypted {@see SecretEnvelope} is persisted, independent of how it was
 * encrypted. The only implementation in this block is a single encrypted file
 * holding all secrets' envelopes; this seam exists so a future store (e.g. one
 * envelope per object-storage key) can be swapped in without touching the
 * provider/cipher/key layers above it.
 */
interface SecretStoreInterface
{
    /** @throws SecretNotFoundException if nothing has been persisted yet */
    public function load(): SecretEnvelope;

    public function save(SecretEnvelope $envelope): void;

    public function exists(): bool;
}
