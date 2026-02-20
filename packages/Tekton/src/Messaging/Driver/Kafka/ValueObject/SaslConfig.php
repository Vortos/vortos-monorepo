<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Driver\Kafka\ValueObject;

/**
 * SASL authentication configuration for Kafka broker connections.
 *
 * Immutable value object. Use the named static factories instead of constructor.
 * Always use SASL with SSL enabled in production — never send credentials unencrypted.
 */
final class SaslConfig
{
    private function __construct(
        public readonly string $mechanism,
        public readonly string $username,
        public readonly string $password
    ) {}

    /**
     * Use PLAIN mechanism (Simple username/password).
     * Warning: Only use this with SSL enabled.
     */
    public static function plain(string $username, string $password): self
    {
        return new self('PLAIN', $username, $password);
    }

    /**
     * Use SCRAM-SHA-256 mechanism (Industry Standard for AWS MSK / Confluent).
     */
    public static function scramSha256(string $username, string $password): self
    {
        return new self('SCRAM-SHA-256', $username, $password);
    }

    /**
     * Use SCRAM-SHA-512 mechanism (Higher security).
     */
    public static function scramSha512(string $username, string $password): self
    {
        return new self('SCRAM-SHA-512', $username, $password);
    }

    public function toArray(): array
    {
        return [
            'mechanism' => $this->mechanism,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }
}
