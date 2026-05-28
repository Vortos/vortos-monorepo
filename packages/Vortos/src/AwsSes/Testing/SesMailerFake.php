<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Testing;

use DateTimeImmutable;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * In-memory mailer for use in application tests.
 *
 * Swap this for MailerInterface in the container and call assertEmailSent()
 * (or inspect sent() directly) after exercising code that sends email.
 *
 * Usage in PHPUnit:
 *   $fake = new SesMailerFake();
 *   $container->set(MailerInterface::class, $fake);
 *
 *   $service->doSomethingThatSendsEmail();
 *
 *   $fake->assertSent(1);
 *   $fake->sent()->to('user@example.com')->assertCount(1);
 */
final class SesMailerFake implements MailerInterface
{
    /** @var Email[] */
    private array $sent = [];

    private ?\Throwable $nextException = null;

    private int $messageCounter = 0;

    public function send(Email $email): SentEmail
    {
        if ($this->nextException !== null) {
            $error                = $this->nextException;
            $this->nextException  = null;
            throw $error;
        }

        $this->sent[] = $email;

        return new SentEmail(
            messageId:      'fake-' . ++$this->messageCounter,
            sentAt:         new DateTimeImmutable(),
            recipientCount: count($email->getAllRecipients()),
            driver:         'fake',
            region:         null,
        );
    }

    /** Make the next send() call throw the given exception. */
    public function failNextWith(\Throwable $error): self
    {
        $this->nextException = $error;
        return $this;
    }

    public function reset(): self
    {
        $this->sent          = [];
        $this->nextException = null;
        $this->messageCounter = 0;
        return $this;
    }

    /** All emails that have been sent, as a queryable collection. */
    public function sent(): SentEmailCollection
    {
        return new SentEmailCollection($this->sent);
    }

    public function sentCount(): int
    {
        return count($this->sent);
    }

    // ── Inline assertions (PHPUnit-compatible) ──────────────────────────────

    public function assertSent(int $expectedCount, string $message = ''): void
    {
        $actual = $this->sentCount();
        if ($actual !== $expectedCount) {
            $msg = $message !== ''
                ? $message
                : sprintf('Expected %d email(s) to be sent, but %d were sent.', $expectedCount, $actual);
            throw new \AssertionError($msg);
        }
    }

    public function assertNothingSent(string $message = ''): void
    {
        $this->assertSent(0, $message !== '' ? $message : 'Expected no emails to be sent, but some were sent.');
    }

    public function assertSentTo(string $address, string $message = ''): void
    {
        $found = $this->sent()->to($address)->count() > 0;
        if (!$found) {
            throw new \AssertionError(
                $message !== '' ? $message : sprintf('No email was sent to "%s".', $address),
            );
        }
    }

    public function assertSentWithSubject(string $subject, string $message = ''): void
    {
        $found = $this->sent()->withSubject($subject)->count() > 0;
        if (!$found) {
            throw new \AssertionError(
                $message !== '' ? $message : sprintf('No email was sent with subject "%s".', $subject),
            );
        }
    }
}
