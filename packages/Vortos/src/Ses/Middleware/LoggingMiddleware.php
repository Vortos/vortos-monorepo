<?php

declare(strict_types=1);

namespace Vortos\Ses\Middleware;

use Psr\Log\LoggerInterface;
use Vortos\Ses\Attribute\AsEmailMiddleware;
use Vortos\Ses\Contract\EmailMiddlewareInterface;
use Vortos\Ses\ValueObject\Email;
use Vortos\Ses\ValueObject\SentEmail;

/**
 * Logs every email send attempt at INFO level, and every failure at ERROR level.
 *
 * Priority 900 — runs early so failures from any lower-priority middleware are
 * captured. Adds a structured log with to-addresses, subject, driver, and latency.
 */
#[AsEmailMiddleware(priority: 900)]
final class LoggingMiddleware implements EmailMiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function process(Email $email, callable $next): SentEmail
    {
        $start = hrtime(true);

        try {
            $result = $next($email);

            $this->logger->info('ses.mailer: email sent', [
                'to'         => $email->getTo(),
                'subject'    => $email->getSubject(),
                'driver'     => $result->driver(),
                'message_id' => $result->messageId(),
                'latency_ms' => $this->ms($start),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('ses.mailer: email send failed', [
                'to'         => $email->getTo(),
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
