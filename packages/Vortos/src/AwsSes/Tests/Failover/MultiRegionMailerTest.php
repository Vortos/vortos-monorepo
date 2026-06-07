<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Failover;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\Exception\MailSendException;
use Vortos\AwsSes\Failover\CircuitBreaker;
use Vortos\AwsSes\Failover\CircuitBreakerState;
use Vortos\AwsSes\Failover\MultiRegionMailer;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class MultiRegionMailerTest extends TestCase
{
    private function makeEmail(): Email
    {
        return Email::new()
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Test')
            ->htmlBody('<p>Hello</p>');
    }

    private function sentEmail(): SentEmail
    {
        return new SentEmail(
            messageId:      'msg-123',
            sentAt:         new \DateTimeImmutable(),
            recipientCount: 1,
            driver:         'ses',
            region:         'us-east-1',
        );
    }

    private function succeedingMailer(): MailerInterface
    {
        $sent = $this->sentEmail();
        return new class($sent) implements MailerInterface {
            public function __construct(private readonly SentEmail $sent) {}
            public function send(Email $email): SentEmail { return $this->sent; }
        };
    }

    private function failingMailer(\Throwable $error): MailerInterface
    {
        return new class($error) implements MailerInterface {
            public function __construct(private readonly \Throwable $error) {}
            public function send(Email $email): SentEmail { throw $this->error; }
        };
    }

    private function makeMultiRegion(
        MailerInterface $primary,
        MailerInterface $fallback,
        int $threshold = 3,
    ): MultiRegionMailer {
        return new MultiRegionMailer(
            primary:        $primary,
            fallback:       $fallback,
            primaryBreaker: new CircuitBreaker($threshold, 60),
            fallbackBreaker: new CircuitBreaker($threshold, 60),
            logger:         new NullLogger(),
        );
    }

    public function test_primary_success_returns_sent_email(): void
    {
        $mailer = $this->makeMultiRegion($this->succeedingMailer(), $this->failingMailer(new \RuntimeException()));
        $result = $mailer->send($this->makeEmail());
        $this->assertSame('msg-123', $result->messageId());
    }

    public function test_falls_back_when_primary_fails(): void
    {
        $fallback = $this->succeedingMailer();
        $mailer   = $this->makeMultiRegion(
            $this->failingMailer(MailSendException::fromSesError('500', 'error')),
            $fallback,
            threshold: 3,
        );

        $result = $mailer->send($this->makeEmail());
        $this->assertSame('msg-123', $result->messageId());
    }

    public function test_primary_circuit_trips_after_threshold(): void
    {
        $mailer = $this->makeMultiRegion(
            $this->failingMailer(new \RuntimeException('down')),
            $this->succeedingMailer(),
            threshold: 2,
        );

        // First two calls trip the primary circuit
        $mailer->send($this->makeEmail());
        $mailer->send($this->makeEmail());

        $this->assertSame(CircuitBreakerState::Open, $mailer->primaryCircuitState());
    }

    public function test_both_regions_down_throws(): void
    {
        $mailer = $this->makeMultiRegion(
            $this->failingMailer(new \RuntimeException('primary down')),
            $this->failingMailer(new \RuntimeException('fallback down')),
        );

        $this->expectException(\RuntimeException::class);
        $mailer->send($this->makeEmail());
    }

    public function test_skips_primary_when_circuit_open_and_uses_fallback(): void
    {
        $primaryBreaker  = new CircuitBreaker(1, 3600);
        $fallbackBreaker = new CircuitBreaker(3, 60);

        $primaryBreaker->recordFailure(); // open immediately (threshold=1)
        $this->assertSame(CircuitBreakerState::Open, $primaryBreaker->state());

        $mailer = new MultiRegionMailer(
            primary:         $this->failingMailer(new \RuntimeException('should not be called')),
            fallback:        $this->succeedingMailer(),
            primaryBreaker:  $primaryBreaker,
            fallbackBreaker: $fallbackBreaker,
            logger:          new NullLogger(),
        );

        $result = $mailer->send($this->makeEmail());
        $this->assertSame('msg-123', $result->messageId());
        // Primary circuit should still be open (we never called it)
        $this->assertSame(CircuitBreakerState::Open, $mailer->primaryCircuitState());
    }

    public function test_both_circuits_open_throws_immediately(): void
    {
        $primaryBreaker  = new CircuitBreaker(1, 3600);
        $fallbackBreaker = new CircuitBreaker(1, 3600);

        $primaryBreaker->recordFailure();
        $fallbackBreaker->recordFailure();

        $mailer = new MultiRegionMailer(
            primary:         $this->failingMailer(new \RuntimeException()),
            fallback:        $this->failingMailer(new \RuntimeException()),
            primaryBreaker:  $primaryBreaker,
            fallbackBreaker: $fallbackBreaker,
            logger:          new NullLogger(),
        );

        $this->expectException(MailSendException::class);
        $mailer->send($this->makeEmail());
    }

    public function test_primary_success_closes_half_open_circuit(): void
    {
        $primaryBreaker  = new CircuitBreaker(1, 0); // resets immediately
        $fallbackBreaker = new CircuitBreaker(3, 60);

        $primaryBreaker->recordFailure(); // open
        $primaryBreaker->isAvailable();   // transitions to HalfOpen

        $mailer = new MultiRegionMailer(
            primary:         $this->succeedingMailer(),
            fallback:        $this->failingMailer(new \RuntimeException()),
            primaryBreaker:  $primaryBreaker,
            fallbackBreaker: $fallbackBreaker,
            logger:          new NullLogger(),
        );

        $mailer->send($this->makeEmail());
        $this->assertSame(CircuitBreakerState::Closed, $mailer->primaryCircuitState());
    }
}
