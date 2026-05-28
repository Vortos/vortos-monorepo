<?php

declare(strict_types=1);

namespace Vortos\AwsSes\ValueObject;

use Vortos\AwsSes\Exception\InvalidEmailAddressException;

final class Email
{
    /** @var EmailAddress[] */
    private array $to = [];

    /** @var EmailAddress[] */
    private array $cc = [];

    /** @var EmailAddress[] */
    private array $bcc = [];

    private ?EmailAddress $from = null;
    private ?EmailAddress $replyTo = null;
    private string $subject = '';
    private ?string $htmlBody = null;
    private ?string $textBody = null;

    /** @var array<string, string> */
    private array $headers = [];

    /** @var Attachment[] */
    private array $attachments = [];

    /** @var array<string, string> Internal bag passed through middleware, never sent to SES */
    private array $metadata = [];

    private function __construct() {}

    public static function new(): self
    {
        return new self();
    }

    /**
     * @param EmailAddress|string $address
     */
    public function to(EmailAddress|string $address, ?string $name = null): static
    {
        $this->to[] = $address instanceof EmailAddress
            ? $address
            : new EmailAddress($address, $name);
        return $this;
    }

    /**
     * @param EmailAddress|string $address
     */
    public function cc(EmailAddress|string $address, ?string $name = null): static
    {
        $this->cc[] = $address instanceof EmailAddress
            ? $address
            : new EmailAddress($address, $name);
        return $this;
    }

    /**
     * @param EmailAddress|string $address
     */
    public function bcc(EmailAddress|string $address, ?string $name = null): static
    {
        $this->bcc[] = $address instanceof EmailAddress
            ? $address
            : new EmailAddress($address, $name);
        return $this;
    }

    /**
     * @param EmailAddress|string $address
     */
    public function from(EmailAddress|string $address, ?string $name = null): static
    {
        $this->from = $address instanceof EmailAddress
            ? $address
            : new EmailAddress($address, $name);
        return $this;
    }

    /**
     * @param EmailAddress|string $address
     */
    public function replyTo(EmailAddress|string $address, ?string $name = null): static
    {
        $this->replyTo = $address instanceof EmailAddress
            ? $address
            : new EmailAddress($address, $name);
        return $this;
    }

    public function subject(string $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    public function htmlBody(string $html): static
    {
        $this->htmlBody = $html;
        return $this;
    }

    public function textBody(string $text): static
    {
        $this->textBody = $text;
        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function attach(Attachment $attachment): static
    {
        $this->attachments[] = $attachment;
        return $this;
    }

    /**
     * Internal metadata bag — passed through the middleware stack, never sent to SES.
     * Used by middleware to inject client_token, domain_event_id, etc.
     */
    public function withMeta(string $key, string $value): static
    {
        $clone = clone $this;
        $clone->metadata[$key] = $value;
        return $clone;
    }

    /**
     * Validates that the email is ready to send. Called by the middleware stack.
     *
     * @throws \LogicException
     */
    public function validate(): void
    {
        if (count($this->to) === 0) {
            throw new \LogicException('Email must have at least one "to" recipient.');
        }

        if ($this->subject === '') {
            throw new \LogicException('Email subject cannot be empty.');
        }

        if ($this->htmlBody === null && $this->textBody === null) {
            throw new \LogicException('Email must have at least one body: htmlBody or textBody.');
        }
    }

    /** @return EmailAddress[] */
    public function getTo(): array { return $this->to; }

    /** @return EmailAddress[] */
    public function getCc(): array { return $this->cc; }

    /** @return EmailAddress[] */
    public function getBcc(): array { return $this->bcc; }

    public function getFrom(): ?EmailAddress { return $this->from; }

    public function getReplyTo(): ?EmailAddress { return $this->replyTo; }

    public function getSubject(): string { return $this->subject; }

    public function getHtmlBody(): ?string { return $this->htmlBody; }

    public function getTextBody(): ?string { return $this->textBody; }

    /** @return array<string, string> */
    public function getHeaders(): array { return $this->headers; }

    /** @return Attachment[] */
    public function getAttachments(): array { return $this->attachments; }

    /** @return array<string, string> */
    public function getMetadata(): array { return $this->metadata; }

    public function getMeta(string $key, ?string $default = null): ?string
    {
        return $this->metadata[$key] ?? $default;
    }

    /** @return EmailAddress[] All to + cc + bcc recipients */
    public function getAllRecipients(): array
    {
        return array_merge($this->to, $this->cc, $this->bcc);
    }

    public function hasAttachment(string $filename): bool
    {
        foreach ($this->attachments as $attachment) {
            if ($attachment->filename() === $filename) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a clone with the given email addresses removed from to/cc/bcc lists.
     *
     * @param string[] $suppressedEmails Lowercase-normalised email addresses to remove.
     */
    public function withFilteredRecipients(array $suppressedEmails): static
    {
        if ($suppressedEmails === []) {
            return $this;
        }

        $suppressed = array_flip(array_map('strtolower', $suppressedEmails));
        $keep       = static fn(EmailAddress $a): bool => !isset($suppressed[strtolower($a->address())]);

        $clone      = clone $this;
        $clone->to  = array_values(array_filter($this->to,  $keep));
        $clone->cc  = array_values(array_filter($this->cc,  $keep));
        $clone->bcc = array_values(array_filter($this->bcc, $keep));

        return $clone;
    }
}
