<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Signature;

final readonly class VerificationPolicy
{
    private function __construct(
        public ?string $issuer,
        public ?string $sanRegex,
        public ?string $publicKeyFingerprint,
    ) {}

    public static function keyless(string $issuer, string $sanRegex): self
    {
        if ($issuer === '') {
            throw new \InvalidArgumentException('Keyless verification policy issuer must not be empty.');
        }
        if ($sanRegex === '') {
            throw new \InvalidArgumentException('Keyless verification policy SAN regex must not be empty.');
        }

        return new self($issuer, $sanRegex, null);
    }

    public static function publicKey(string $fingerprint): self
    {
        if ($fingerprint === '') {
            throw new \InvalidArgumentException('Public key fingerprint must not be empty.');
        }

        return new self(null, null, $fingerprint);
    }

    public function isKeyless(): bool
    {
        return $this->issuer !== null && $this->sanRegex !== null;
    }

    public function matchesIdentity(string $issuer, string $san): bool
    {
        if (!$this->isKeyless()) {
            return false;
        }

        if ($this->issuer !== $issuer) {
            return false;
        }

        return preg_match('#' . str_replace('#', '\\#', $this->sanRegex) . '#', $san) === 1;
    }

    public function matchesFingerprint(string $fingerprint): bool
    {
        if ($this->publicKeyFingerprint === null) {
            return false;
        }

        return hash_equals($this->publicKeyFingerprint, $fingerprint);
    }

    /** @return array{issuer: ?string, san_regex: ?string, public_key_fingerprint: ?string} */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'san_regex' => $this->sanRegex,
            'public_key_fingerprint' => $this->publicKeyFingerprint,
        ];
    }

    /** @param array{issuer?: ?string, san_regex?: ?string, public_key_fingerprint?: ?string} $data */
    public static function fromArray(array $data): self
    {
        $issuer = $data['issuer'] ?? null;
        $sanRegex = $data['san_regex'] ?? null;
        $fingerprint = $data['public_key_fingerprint'] ?? null;

        if ($issuer !== null && $sanRegex !== null) {
            return self::keyless($issuer, $sanRegex);
        }

        if ($fingerprint !== null) {
            return self::publicKey($fingerprint);
        }

        throw new \InvalidArgumentException(
            'VerificationPolicy must specify either keyless identity (issuer + san_regex) or a public_key_fingerprint.',
        );
    }
}
