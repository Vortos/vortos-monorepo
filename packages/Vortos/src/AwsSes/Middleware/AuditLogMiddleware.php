<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Middleware;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Attribute\AsEmailMiddleware;
use Vortos\AwsSes\Contract\EmailMiddlewareInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Records every successfully sent email to the aws_ses_audit_log table.
 *
 * Runs at priority 500 — below all guard middleware (suppression, rate-limit)
 * so only emails that actually reach the transport are logged.
 * Audit failures are swallowed and logged as errors to prevent blocking delivery.
 */
#[AsEmailMiddleware(priority: 500)]
final class AuditLogMiddleware implements EmailMiddlewareInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly string $tableName,
    ) {}

    public function process(Email $email, callable $next): SentEmail
    {
        $result = $next($email);

        try {
            $recipients = array_map(
                static fn($a) => $a->address(),
                $email->getTo(),
            );

            $this->connection->insert($this->tableName, [
                'message_id' => $result->messageId(),
                'outbox_id'  => $email->getMeta('outbox_id'),
                'recipients' => json_encode($recipients, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'subject'    => $email->getSubject(),
                'driver'     => $result->driver(),
                'region'     => $result->region(),
                'sent_at'    => $result->sentAt()->format('Y-m-d H:i:s'),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('AuditLogMiddleware: failed to write audit record', [
                'message_id' => $result->messageId(),
                'error'      => $e->getMessage(),
            ]);
        }

        return $result;
    }
}
