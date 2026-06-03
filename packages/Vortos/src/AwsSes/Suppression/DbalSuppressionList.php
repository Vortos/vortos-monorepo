<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Suppression;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;
use Vortos\AwsSes\Contract\SuppressionListInterface;
use Vortos\AwsSes\ValueObject\EmailAddress;

/**
 * DBAL-backed suppression list.
 *
 * Stores a local mirror of suppressed addresses — seeded from the AWS account-level
 * suppression list via the sync command, and updated in real-time on bounce/complaint
 * SNS notifications (Phases 9–10).
 *
 * All email addresses are stored and compared lowercase. UUIDs are stored as strings
 * to stay compatible with both PostgreSQL (uuid type) and MySQL (varchar).
 */
final class DbalSuppressionList implements SuppressionListInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName,
    ) {}

    public function isSuppressed(EmailAddress $address): bool
    {
        $result = $this->connection->executeQuery(
            "SELECT 1 FROM {$this->tableName} WHERE email_address = :email LIMIT 1",
            ['email' => strtolower($address->address())],
        );

        return (bool) $result->fetchOne();
    }

    public function suppress(EmailAddress $address, SuppressionReason $reason): void
    {
        $now   = new DateTimeImmutable();
        $email = strtolower($address->address());

        try {
            $this->connection->insert($this->tableName, [
                'id'           => Uuid::v7()->toRfc4122(),
                'email_address' => $email,
                'reason'       => $reason->value,
                'suppressed_at' => $now->format('Y-m-d H:i:s.u'),
                'created_at'   => $now->format('Y-m-d H:i:s.u'),
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->connection->executeStatement(
                "UPDATE {$this->tableName}
                 SET reason = :reason, suppressed_at = :suppressedAt
                 WHERE email_address = :email",
                [
                    'reason'       => $reason->value,
                    'suppressedAt' => $now->format('Y-m-d H:i:s.u'),
                    'email'        => $email,
                ],
            );
        }
    }

    public function unsuppress(EmailAddress $address): void
    {
        $this->connection->executeStatement(
            "DELETE FROM {$this->tableName} WHERE email_address = :email",
            ['email' => strtolower($address->address())],
        );
    }

    public function list(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->connection->executeQuery(
            "SELECT id, email_address, reason, suppressed_at, created_at
             FROM {$this->tableName}
             ORDER BY suppressed_at DESC
             LIMIT :limit OFFSET :offset",
            ['limit' => max(1, $limit), 'offset' => max(0, $offset)],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        )->fetchAllAssociative();

        return array_map([$this, 'hydrate'], $rows);
    }

    private function hydrate(array $row): SuppressionEntry
    {
        return new SuppressionEntry(
            id:           (string) $row['id'],
            address:      new EmailAddress((string) $row['email_address']),
            reason:       SuppressionReason::from((string) $row['reason']),
            suppressedAt: new DateTimeImmutable((string) $row['suppressed_at']),
            createdAt:    new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
