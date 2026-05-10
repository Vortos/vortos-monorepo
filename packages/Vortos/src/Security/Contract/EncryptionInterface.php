<?php

declare(strict_types=1);

namespace Vortos\Security\Contract;

interface EncryptionInterface
{
    /**
     * Encrypts $plaintext and returns a base64-encoded ciphertext string.
     *
     * @param string $context Additional authenticated data (AAD) — typically the
     *                        field name or a domain context string. Prevents a ciphertext
     *                        from being moved to a different field without detection.
     *                        Must match on decryption.
     */
    public function encrypt(string $plaintext, string $context = ''): string;

    /**
     * Decrypts a base64-encoded ciphertext produced by encrypt().
     *
     * @throws \RuntimeException When decryption fails (wrong key, tampered data, wrong context).
     */
    public function decrypt(string $ciphertext, string $context = ''): string;
}
