<?php

declare(strict_types=1);

namespace Vortos\AwsSes\ValueObject;

/**
 * Represents a fully-composed MIME message for advanced cases
 * (complex multipart structures, S/MIME signing, etc.).
 *
 * Use Email for the typical transactional case.
 * Use RawEmail when you need full MIME control.
 */
final class RawEmail
{
    /** @var EmailAddress[] */
    private array $destinations = [];

    /** @var array<string, string> Internal metadata bag, never sent */
    private array $metadata = [];

    public function __construct(
        private readonly string $rawMessage,
        private readonly ?EmailAddress $from = null,
    ) {}

    public function destination(EmailAddress|string $address): static
    {
        $clone = clone $this;
        $clone->destinations[] = $address instanceof EmailAddress
            ? $address
            : new EmailAddress($address);
        return $clone;
    }

    public function withMeta(string $key, string $value): static
    {
        $clone = clone $this;
        $clone->metadata[$key] = $value;
        return $clone;
    }

    public function getRawMessage(): string
    {
        return $this->rawMessage;
    }

    public function getFrom(): ?EmailAddress
    {
        return $this->from;
    }

    /** @return EmailAddress[] */
    public function getDestinations(): array
    {
        return $this->destinations;
    }

    /** @return array<string, string> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMeta(string $key, ?string $default = null): ?string
    {
        return $this->metadata[$key] ?? $default;
    }
}
