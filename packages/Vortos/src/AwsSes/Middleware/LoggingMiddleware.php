<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Middleware;

use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Attribute\AsEmailMiddleware;
use Vortos\AwsSes\Config\AwsSesObservabilitySection;
use Vortos\AwsSes\Contract\EmailMiddlewareInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\EmailAddress;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Logs every email send attempt at INFO level, and every failure at ERROR level.
 *
 * Priority 900 — runs early so failures from any lower-priority middleware are
 * captured. Adds a structured log with to-addresses, subject, driver, and latency.
 */
#[AsEmailMiddleware(priority: 900)]
final class LoggingMiddleware implements EmailMiddlewareInterface
{
    /** @param string[] $disabledSections */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $disabledSections = [],
    ) {}

    public function process(Email $email, callable $next): SentEmail
    {
        if (in_array(AwsSesObservabilitySection::Send->value, $this->disabledSections, true)) {
            return $next($email);
        }

        $start = hrtime(true);

        try {
            $result = $next($email);

            $this->logger->info('ses.mailer: email sent', [
                'to'         => array_map(fn(EmailAddress $a) => $a->address(), $email->getTo()),
                'subject'    => $email->getSubject(),
                'driver'     => $result->driver(),
                'message_id' => $result->messageId(),
                'latency_ms' => $this->ms($start),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('ses.mailer: email send failed', [
                'to'         => array_map(fn(EmailAddress $a) => $a->address(), $email->getTo()),
                'subject'    => $email->getSubject(),
                'error'      => $e->getMessage(),
                'latency_ms' => $this->ms($start),
            ]);

            throw $e;
        }
    }

    private function ms(int $startNs): float
    {
        return round((hrtime(true) - $startNs) / 1_000_000, 2);
    }
}
