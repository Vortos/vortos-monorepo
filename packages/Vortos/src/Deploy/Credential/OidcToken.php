<?php

declare(strict_types=1);

namespace Vortos\Deploy\Credential;

use Vortos\Secrets\Value\SecretValue;

final readonly class OidcToken
{
    /**
     * @param array<string, string> $claims Parsed (unverified) claims for routing
     */
    public function __construct(
        public SecretValue $rawJwt,
        public array $claims,
        public \DateTimeImmutable $expiresAt,
    ) {}

    public function claim(string $name): ?string
    {
        return $this->claims[$name] ?? null;
    }

    public function subject(): ?string
    {
        return $this->claim('sub');
    }

    public function repository(): ?string
    {
        return $this->claim('repository');
    }

    public function ref(): ?string
    {
        return $this->claim('ref');
    }

    public function environment(): ?string
    {
        return $this->claim('environment');
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public static function fromJwt(string $jwt): self
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid JWT format: expected 3 parts.');
        }

        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payloadJson === false) {
            throw new \InvalidArgumentException('Invalid JWT payload encoding.');
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid JWT payload: not a JSON object.');
        }

        $claims = [];
        foreach (['sub', 'aud', 'repository', 'ref', 'environment', 'iss'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $claims[$key] = $payload[$key];
            }
        }

        $exp = isset($payload['exp']) && is_int($payload['exp'])
            ? (new \DateTimeImmutable())->setTimestamp($payload['exp'])
            : new \DateTimeImmutable('+1 hour');

        return new self(
            rawJwt: SecretValue::fromString($jwt),
            claims: $claims,
            expiresAt: $exp,
        );
    }
}
