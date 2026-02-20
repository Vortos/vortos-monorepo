<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Driver\Kafka\ValueObject;

/**
 * SSL/TLS configuration for encrypted Kafka broker connections.
 *
 * Fluent value object. Use SslConfig::create() then chain methods as needed.
 * For mTLS (mutual TLS), provide both cert() and key().
 * For one-way TLS (most common), ca() alone is sufficient.
 *
 * Example:
 *   SslConfig::create()
 *       ->ca('/etc/ssl/kafka/ca.pem')
 *       ->cert('/etc/ssl/kafka/service.cert')
 *       ->key('/etc/ssl/kafka/service.key');
 */
final class SslConfig
{
    private ?string $caLocation = null;
    private ?string $certificateLocation = null;
    private ?string $keyLocation = null;
    private ?string $keyPassword = null;
    private bool $verifyPeerEnabled = true;

    private function __construct() {}

    public static function create(): self
    {
        return new self();
    }

    /**
     * Path to the CA certificate file used to verify the broker's identity.
     * Required for connecting to public/cloud Kafka clusters (MSK, Confluent Cloud).
     */
    public function ca(string $path): self
    {
        $this->caLocation = $path;
        return $this;
    }

    /**
     * Path to the client certificate file for mTLS authentication.
     * Only required when the broker demands client certificate verification.
     */
    public function cert(string $path): self
    {
        $this->certificateLocation = $path;
        return $this;
    }

    /**
     * Path to the client private key file for mTLS authentication.
     * Optionally provide a password if the key file is encrypted.
     */
    public function key(string $path, ?string $password = null): self
    {
        $this->keyLocation = $path;
        $this->keyPassword = $password;
        return $this;
    }

    /**
     * Whether to verify the broker's SSL certificate against the CA.
     * Default is true. Set to false only in local development — never in production.
     */
    public function verifyPeer(bool $enable): self
    {
        $this->verifyPeerEnabled = $enable;
        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'ca_location' => $this->caLocation,
            'certificate_location' => $this->certificateLocation,
            'key_location' => $this->keyLocation,
            'key_password' => $this->keyPassword,
            'verify_peer' => $this->verifyPeerEnabled,
        ], fn($value) => $value !== null);
    }
}
