<?php

declare(strict_types=1);

namespace Vortos\Secrets\Crypto;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The encrypted-at-rest representation of one secret: an AEAD-authenticated
 * payload plus the data key (DEK), sealed once per recipient.
 *
 * **Envelope encryption**: the DEK encrypts the payload; the DEK itself is sealed
 * (X25519 `crypto_box_seal`) to each recipient's public key. Re-keying a recipient
 * only re-wraps the DEK — the AEAD payload is never re-encrypted. This is what
 * keeps recipient rotation cheap and is Block-19/20-ready (backup envelope reuse).
 *
 * Versioned (`schemaVersion`) + canonically serializable (base64, sorted recipient
 * map) so the on-disk JSON is deterministic and a future format change cannot
 * silently re-classify an old envelope.
 */
final readonly class SecretEnvelope
{
    /** @param array<string, string> $wrappedDeks recipientId => sealed DEK (raw binary) */
    public function __construct(
        public int $schemaVersion,
        public string $aeadCiphertext,
        public string $nonce,
        public string $aad,
        public array $wrappedDeks,
        public DateTimeImmutable $createdAt,
    ) {
        if ($schemaVersion < 1) {
            throw new InvalidArgumentException('SecretEnvelope::$schemaVersion must be >= 1.');
        }
        if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new InvalidArgumentException(sprintf(
                'SecretEnvelope::$nonce must be %d bytes.',
                SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES,
            ));
        }
        if ($wrappedDeks === []) {
            throw new InvalidArgumentException('SecretEnvelope must have at least one wrapped DEK (recipient).');
        }
        foreach ($wrappedDeks as $recipientId => $wrapped) {
            // @phpstan-ignore-next-line function.alreadyNarrowedType (defends against callers violating the PHPDoc type at runtime, since PHP itself does not enforce it)
            if (!is_string($recipientId) || $recipientId === '') {
                throw new InvalidArgumentException('Recipient id must be a non-empty string.');
            }
            if ($wrapped === '') {
                throw new InvalidArgumentException("Wrapped DEK for recipient '{$recipientId}' must not be empty.");
            }
        }
    }

    /**
     * Canonical, deterministic serialization: binary fields are base64-encoded and
     * the recipient map is key-sorted, so two byte-identical envelopes always
     * serialize identically.
     *
     * @return array{schemaVersion: int, aeadCiphertext: string, nonce: string, aad: string, wrappedDeks: array<string, string>, createdAt: string}
     */
    public function toArray(): array
    {
        $wrappedDeks = array_map(static fn (string $w): string => base64_encode($w), $this->wrappedDeks);
        ksort($wrappedDeks);

        return [
            'schemaVersion' => $this->schemaVersion,
            'aeadCiphertext' => base64_encode($this->aeadCiphertext),
            'nonce' => base64_encode($this->nonce),
            'aad' => $this->aad,
            'wrappedDeks' => $wrappedDeks,
            'createdAt' => $this->createdAt->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * @param array{schemaVersion: int, aeadCiphertext: string, nonce: string, aad: string, wrappedDeks: array<string, string>, createdAt: string} $data
     */
    public static function fromArray(array $data): self
    {
        $decodedCiphertext = base64_decode($data['aeadCiphertext'], true);
        $decodedNonce = base64_decode($data['nonce'], true);
        if ($decodedCiphertext === false || $decodedNonce === false) {
            throw new InvalidArgumentException('SecretEnvelope ciphertext/nonce must be valid base64.');
        }

        $wrappedDeks = [];
        foreach ($data['wrappedDeks'] as $recipientId => $wrapped) {
            $decoded = base64_decode($wrapped, true);
            if ($decoded === false) {
                throw new InvalidArgumentException("Wrapped DEK for recipient '{$recipientId}' must be valid base64.");
            }
            $wrappedDeks[$recipientId] = $decoded;
        }

        return new self(
            $data['schemaVersion'],
            $decodedCiphertext,
            $decodedNonce,
            $data['aad'],
            $wrappedDeks,
            new DateTimeImmutable($data['createdAt']),
        );
    }

    /** A new envelope sharing the same AEAD payload, with one additional/replaced recipient wrap. */
    public function withWrappedDek(string $recipientId, string $wrappedDek): self
    {
        $wrappedDeks = $this->wrappedDeks;
        $wrappedDeks[$recipientId] = $wrappedDek;

        return new self($this->schemaVersion, $this->aeadCiphertext, $this->nonce, $this->aad, $wrappedDeks, $this->createdAt);
    }

    /** A new envelope with one recipient's wrap removed (re-keying off a retired recipient). */
    public function withoutRecipient(string $recipientId): self
    {
        $wrappedDeks = $this->wrappedDeks;
        unset($wrappedDeks[$recipientId]);

        return new self($this->schemaVersion, $this->aeadCiphertext, $this->nonce, $this->aad, $wrappedDeks, $this->createdAt);
    }
}
