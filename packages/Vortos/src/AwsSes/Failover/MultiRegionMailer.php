<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Failover;

use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Contract\MailerInterface;
use Vortos\AwsSes\Exception\MailSendException;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Wraps a primary and fallback SES mailer with per-region circuit breakers.
 *
 * Send order:
 *   1. Try primary if its circuit is not open.
 *   2. On failure, record it and try fallback if its circuit is not open.
 *   3. If both circuits are open, throw immediately.
 *   4. If both attempts fail, rethrow the last exception.
 */
final class MultiRegionMailer implements MailerInterface
{
    public function __construct(
        private readonly MailerInterface $primary,
        private readonly MailerInterface $fallback,
        private readonly CircuitBreaker $primaryBreaker,
        private readonly CircuitBreaker $fallbackBreaker,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(Email $email): SentEmail
    {
        $primarySkipped = false;

        if ($this->primaryBreaker->isAvailable()) {
            try {
                $result = $this->primary->send($email);
                $this->primaryBreaker->recordSuccess();
                return $result;
            } catch (\Throwable $e) {
                $this->primaryBreaker->recordFailure();
                $this->logger->warning('Primary SES region failed, attempting fallback', [
                    'error'         => $e->getMessage(),
                    'circuit_state' => $this->primaryBreaker->state()->name,
                ]);
            }
        } else {
            $primarySkipped = true;
            $this->logger->info('Primary SES circuit is open, routing to fallback region');
        }

        if (!$this->fallbackBreaker->isAvailable()) {
            throw MailSendException::bothRegionsUnavailable();
        }

        try {
            $result = $this->fallback->send($email);
            $this->fallbackBreaker->recordSuccess();

            if (!$primarySkipped) {
                $this->logger->info('Fallback SES region delivered successfully');
            }

            return $result;
        } catch (\Throwable $e) {
            $this->fallbackBreaker->recordFailure();
            $this->logger->error('Fallback SES region also failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function primaryCircuitState(): CircuitBreakerState
    {
        return $this->primaryBreaker->state();
    }

    public function fallbackCircuitState(): CircuitBreakerState
    {
        return $this->fallbackBreaker->state();
    }
}
