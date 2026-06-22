<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Storage;

use Doctrine\DBAL\Connection;
use Vortos\FeatureFlags\ChangeRequest\Approval;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequest;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;
use Vortos\FeatureFlags\ChangeRequest\ChangeType;
use Vortos\FeatureFlags\ChangeRequest\Rejection;
use Vortos\FeatureFlags\Http\Management\CursorEncoder;

final class DatabaseChangeRequestStorage implements ChangeRequestStorageInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function save(ChangeRequest $request): void
    {
        $approvals  = array_map(fn(Approval $a) => $a->toArray(), $request->approvals());
        $rejections = array_map(fn(Rejection $r) => $r->toArray(), $request->rejections());

        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (id, flag_name, project_id, environment, change_type, payload, reason,
                  requested_by, requested_at, status, required_approvals, approvals, rejections,
                  apply_at, expires_at, applied_at, applied_by)
             VALUES
                 (:id, :flag_name, :project_id, :environment, :change_type, :payload, :reason,
                  :requested_by, :requested_at, :status, :required_approvals, :approvals, :rejections,
                  :apply_at, :expires_at, :applied_at, :applied_by)
             ON CONFLICT (id) DO UPDATE SET
                 status             = EXCLUDED.status,
                 approvals          = EXCLUDED.approvals,
                 rejections         = EXCLUDED.rejections,
                 applied_at         = EXCLUDED.applied_at,
                 applied_by         = EXCLUDED.applied_by',
            [
                'id'                => $request->id(),
                'flag_name'         => $request->flagName(),
                'project_id'        => $request->projectId(),
                'environment'       => $request->environment(),
                'change_type'       => $request->changeType()->value,
                'payload'           => json_encode($request->payload(), JSON_THROW_ON_ERROR),
                'reason'            => $request->reason(),
                'requested_by'      => $request->requestedBy(),
                'requested_at'      => $request->requestedAt()->format('Y-m-d H:i:s'),
                'status'            => $request->status()->value,
                'required_approvals' => $request->requiredApprovals(),
                'approvals'         => json_encode($approvals, JSON_THROW_ON_ERROR),
                'rejections'        => json_encode($rejections, JSON_THROW_ON_ERROR),
                'apply_at'          => $request->applyAt()?->format('Y-m-d H:i:s'),
                'expires_at'        => $request->expiresAt()->format('Y-m-d H:i:s'),
                'applied_at'        => $request->appliedAt()?->format('Y-m-d H:i:s'),
                'applied_by'        => $request->appliedBy(),
            ],
        );
    }

    public function findById(string $id): ?ChangeRequest
    {
        $row = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findDueForApplication(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('status = :status AND (apply_at IS NULL OR apply_at <= :now)')
            ->setParameter('status', ChangeRequestStatus::Approved->value)
            ->setParameter('now', (new \DateTimeImmutable())->format('Y-m-d H:i:s'))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findExpired(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('status IN (:pending, :approved) AND expires_at <= :now')
            ->setParameter('pending', ChangeRequestStatus::Pending->value)
            ->setParameter('approved', ChangeRequestStatus::Approved->value)
            ->setParameter('now', (new \DateTimeImmutable())->format('Y-m-d H:i:s'))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findByFlag(
        string $flagName,
        string $projectId,
        string $environment,
        ?ChangeRequestStatus $status = null,
        ?string $afterCursor = null,
        int $limit = 0,
    ): array {
        $qb = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('flag_name = :flag_name AND project_id = :project_id AND environment = :environment')
            ->setParameter('flag_name', $flagName)
            ->setParameter('project_id', $projectId)
            ->setParameter('environment', $environment)
            ->orderBy('requested_at', 'ASC')
            ->addOrderBy('id', 'ASC');

        if ($status !== null) {
            $qb->andWhere('status = :status')->setParameter('status', $status->value);
        }

        if ($afterCursor !== null) {
            $decoded = CursorEncoder::decode($afterCursor);
            if ($decoded !== null) {
                $cursorAt = (new \DateTimeImmutable($decoded['at']))->format('Y-m-d H:i:s');
                $qb->andWhere('(requested_at > :cursor_ra OR (requested_at = :cursor_ra AND id > :cursor_id))')
                    ->setParameter('cursor_ra', $cursorAt)
                    ->setParameter('cursor_id', $decoded['id']);
            }
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    private function hydrate(array $row): ChangeRequest
    {
        $approvalsData  = json_decode((string) $row['approvals'], true, 512, JSON_THROW_ON_ERROR);
        $rejectionsData = json_decode((string) $row['rejections'], true, 512, JSON_THROW_ON_ERROR);

        $approvals  = array_map(fn(array $a) => Approval::fromArray($a), $approvalsData);
        $rejections = array_map(fn(array $r) => Rejection::fromArray($r), $rejectionsData);

        return new ChangeRequest(
            id:                (string) $row['id'],
            flagName:          (string) $row['flag_name'],
            projectId:         (string) $row['project_id'],
            environment:       (string) $row['environment'],
            changeType:        ChangeType::from((string) $row['change_type']),
            payload:           json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR),
            reason:            (string) $row['reason'],
            requestedBy:       (string) $row['requested_by'],
            requestedAt:       new \DateTimeImmutable((string) $row['requested_at']),
            status:            ChangeRequestStatus::from((string) $row['status']),
            requiredApprovals: (int) $row['required_approvals'],
            approvals:         $approvals,
            rejections:        $rejections,
            applyAt:           isset($row['apply_at']) ? new \DateTimeImmutable((string) $row['apply_at']) : null,
            expiresAt:         new \DateTimeImmutable((string) $row['expires_at']),
            appliedAt:         isset($row['applied_at']) ? new \DateTimeImmutable((string) $row['applied_at']) : null,
            appliedBy:         isset($row['applied_by']) ? (string) $row['applied_by'] : null,
        );
    }
}
