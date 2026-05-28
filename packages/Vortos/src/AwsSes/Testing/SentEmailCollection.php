<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Testing;

use Vortos\AwsSes\ValueObject\Email;

/**
 * Fluent query interface over a set of sent Email objects.
 *
 * All filter methods return a new collection so they can be chained:
 *
 *   $fake->sent()
 *       ->to('user@example.com')
 *       ->withSubject('Welcome')
 *       ->assertCount(1);
 */
final class SentEmailCollection
{
    /** @param Email[] $emails */
    public function __construct(private readonly array $emails) {}

    /** Filter to emails that were sent to the given address (any of To/Cc/Bcc). */
    public function to(string $address): self
    {
        $normalised = strtolower($address);

        return new self(array_values(array_filter(
            $this->emails,
            static function (Email $email) use ($normalised): bool {
                foreach ($email->getAllRecipients() as $recipient) {
                    if (strtolower($recipient->address()) === $normalised) {
                        return true;
                    }
                }
                return false;
            },
        )));
    }

    /** Filter to emails sent from the given address. */
    public function from(string $address): self
    {
        $normalised = strtolower($address);

        return new self(array_values(array_filter(
            $this->emails,
            static fn(Email $email): bool =>
                $email->getFrom() !== null &&
                strtolower($email->getFrom()->address()) === $normalised,
        )));
    }

    /** Filter to emails with an exact subject match. */
    public function withSubject(string $subject): self
    {
        return new self(array_values(array_filter(
            $this->emails,
            static fn(Email $email): bool => $email->getSubject() === $subject,
        )));
    }

    /** Filter to emails whose subject contains the given string (case-sensitive). */
    public function withSubjectContaining(string $needle): self
    {
        return new self(array_values(array_filter(
            $this->emails,
            static fn(Email $email): bool => str_contains($email->getSubject(), $needle),
        )));
    }

    /** Filter to emails whose HTML body contains the given string. */
    public function withBodyContaining(string $needle): self
    {
        return new self(array_values(array_filter(
            $this->emails,
            static fn(Email $email): bool =>
                str_contains((string) $email->getHtmlBody(), $needle) ||
                str_contains((string) $email->getTextBody(), $needle),
        )));
    }

    /** Filter to emails that carry a specific metadata key/value. */
    public function withMeta(string $key, string $value): self
    {
        return new self(array_values(array_filter(
            $this->emails,
            static fn(Email $email): bool => $email->getMeta($key) === $value,
        )));
    }

    /** Return the first email in the collection, or null if empty. */
    public function first(): ?Email
    {
        return $this->emails[0] ?? null;
    }

    /** @return Email[] */
    public function all(): array
    {
        return $this->emails;
    }

    public function count(): int
    {
        return count($this->emails);
    }

    public function isEmpty(): bool
    {
        return $this->emails === [];
    }

    // ── Inline assertions ───────────────────────────────────────────────────

    public function assertCount(int $expected, string $message = ''): self
    {
        $actual = $this->count();
        if ($actual !== $expected) {
            $msg = $message !== ''
                ? $message
                : sprintf('Expected %d email(s) in collection, got %d.', $expected, $actual);
            throw new \AssertionError($msg);
        }
        return $this;
    }

    public function assertEmpty(string $message = ''): self
    {
        return $this->assertCount(0, $message !== '' ? $message : 'Expected collection to be empty.');
    }

    public function assertNotEmpty(string $message = ''): self
    {
        if ($this->isEmpty()) {
            throw new \AssertionError($message !== '' ? $message : 'Expected collection to be non-empty.');
        }
        return $this;
    }
}
