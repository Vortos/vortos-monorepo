<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Testing;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Exception\MailSendException;
use Vortos\AwsSes\Testing\SesMailerFake;
use Vortos\AwsSes\ValueObject\Email;

final class SesMailerFakeTest extends TestCase
{
    private function makeEmail(string $to = 'user@example.com', string $subject = 'Hello'): Email
    {
        return Email::new()
            ->from('sender@example.com')
            ->to($to)
            ->subject($subject)
            ->htmlBody('<p>Hi</p>');
    }

    public function test_send_stores_email(): void
    {
        $fake = new SesMailerFake();
        $fake->send($this->makeEmail());
        $this->assertSame(1, $fake->sentCount());
    }

    public function test_send_returns_fake_sent_email(): void
    {
        $fake   = new SesMailerFake();
        $result = $fake->send($this->makeEmail());
        $this->assertStringStartsWith('fake-', $result->messageId());
        $this->assertSame('fake', $result->driver());
    }

    public function test_message_ids_are_unique_per_send(): void
    {
        $fake = new SesMailerFake();
        $a    = $fake->send($this->makeEmail());
        $b    = $fake->send($this->makeEmail());
        $this->assertNotSame($a->messageId(), $b->messageId());
    }

    public function test_fail_next_with_throws_on_next_send(): void
    {
        $fake = new SesMailerFake();
        $fake->failNextWith(MailSendException::fromSesError('500', 'Simulated'));

        $this->expectException(MailSendException::class);
        $fake->send($this->makeEmail());
    }

    public function test_subsequent_sends_succeed_after_fail_next(): void
    {
        $fake = new SesMailerFake();
        $fake->failNextWith(new \RuntimeException('once'));

        try { $fake->send($this->makeEmail()); } catch (\Throwable) {}

        $result = $fake->send($this->makeEmail());
        $this->assertSame('fake-1', $result->messageId());
    }

    public function test_reset_clears_sent_emails(): void
    {
        $fake = new SesMailerFake();
        $fake->send($this->makeEmail());
        $fake->reset();
        $this->assertSame(0, $fake->sentCount());
    }

    public function test_assert_sent_passes_when_count_matches(): void
    {
        $fake = new SesMailerFake();
        $fake->send($this->makeEmail());
        $fake->assertSent(1); // no exception
        $this->assertTrue(true);
    }

    public function test_assert_sent_throws_when_count_mismatches(): void
    {
        $fake = new SesMailerFake();
        $this->expectException(\AssertionError::class);
        $fake->assertSent(1);
    }

    public function test_assert_nothing_sent_passes_when_empty(): void
    {
        $fake = new SesMailerFake();
        $fake->assertNothingSent();
        $this->assertTrue(true);
    }

    public function test_assert_nothing_sent_throws_when_email_was_sent(): void
    {
        $fake = new SesMailerFake();
        $fake->send($this->makeEmail());
        $this->expectException(\AssertionError::class);
        $fake->assertNothingSent();
    }

    public function test_assert_sent_to_passes_when_found(): void
    {
        $fake = new SesMailerFake();
        $fake->send($this->makeEmail('target@example.com'));
        $fake->assertSentTo('target@example.com');
        $this->assertTrue(true);
    }

    public function test_assert_sent_to_throws_when_not_found(): void
    {
        $fake = new SesMailerFake();
        $fake->send($this->makeEmail('other@example.com'));
        $this->expectException(\AssertionError::class);
        $fake->assertSentTo('target@example.com');
    }

    public function test_assert_sent_with_subject_passes(): void
    {
        $fake = new SesMailerFake();
        $fake->send($this->makeEmail(subject: 'Welcome!'));
        $fake->assertSentWithSubject('Welcome!');
        $this->assertTrue(true);
    }

    public function test_assert_sent_with_subject_throws_when_not_found(): void
    {
        $fake = new SesMailerFake();
        $fake->send($this->makeEmail(subject: 'Other'));
        $this->expectException(\AssertionError::class);
        $fake->assertSentWithSubject('Welcome!');
    }
}
