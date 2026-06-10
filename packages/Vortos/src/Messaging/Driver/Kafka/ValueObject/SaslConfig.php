<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\ValueObject;

use Vortos\Foundation\Config\Env;

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
        public readonly string|Env $username,
        public readonly string|Env $password
    ) {}

    /**
     * Use PLAIN mechanism (Simple username/password).
     * Warning: Only use this with SSL enabled.
     */
    public static function plain(string|Env $username, string|Env $password): self
    {
        return new self('PLAIN', $username, $password);
    }

    /**
     * Use SCRAM-SHA-256 mechanism (Industry Standard for AWS MSK / Confluent).
     */
    public static function scramSha256(string|Env $username, string|Env $password): self
    {
        return new self('SCRAM-SHA-256', $username, $password);
    }

    /**
     * Use SCRAM-SHA-512 mechanism (Higher security).
     */
    public static function scramSha512(string|Env $username, string|Env $password): self
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
