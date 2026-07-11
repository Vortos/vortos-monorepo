<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\Storage;

use Doctrine\DBAL\Connection;
use Vortos\FeatureFlags\Guardrail\GuardrailAction;
use Vortos\FeatureFlags\Guardrail\GuardrailCondition;
use Vortos\FeatureFlags\Guardrail\GuardrailPolicy;

final class DatabaseGuardrailPolicyStorage implements GuardrailPolicyStorageInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
    ) {}

    public function save(GuardrailPolicy $policy): void
    {
        $this->connection->executeStatement(
            'INSERT INTO ' . $this->table . '
                 (id, flag_name, project_id, environment, status, action,
                  pause_ramp_target_pct, consecutive_windows, window_seconds,
                  cooldown_seconds, enabled, consecutive_breach_count, conditions,
                  last_evaluated_at, triggered_at, resolved_at, created_at, created_by, ack_required)
             VALUES
                 (:id, :flag_name, :project_id, :environment, :status, :action,
                  :pause_ramp_target_pct, :consecutive_windows, :window_seconds,
                  :cooldown_seconds, :enabled, :consecutive_breach_count, :conditions,
                  :last_evaluated_at, :triggered_at, :resolved_at, :created_at, :created_by, :ack_required)
             ON CONFLICT (id) DO UPDATE SET
                 status                  = EXCLUDED.status,
                 action                  = EXCLUDED.action,
                 pause_ramp_target_pct   = EXCLUDED.pause_ramp_target_pct,
                 consecutive_windows     = EXCLUDED.consecutive_windows,
                 window_seconds          = EXCLUDED.window_seconds,
                 cooldown_seconds        = EXCLUDED.cooldown_seconds,
                 enabled                 = EXCLUDED.enabled,
                 consecutive_breach_count = EXCLUDED.consecutive_breach_count,
                 conditions              = EXCLUDED.conditions,
                 last_evaluated_at       = EXCLUDED.last_evaluated_at,
                 triggered_at            = EXCLUDED.triggered_at,
                 resolved_at             = EXCLUDED.resolved_at,
                 ack_required            = EXCLUDED.ack_required',
            $this->toRow($policy),
        );
    }

    public function findById(string $id): ?GuardrailPolicy
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

    public function findEnabled(string $projectId, string $environment): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('project_id = :project_id AND environment = :environment AND enabled = true')
            ->setParameter('project_id', $projectId)
            ->setParameter('environment', $environment)
            ->orderBy('flag_name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function findDueForEvaluation(\DateTimeImmutable $before, int $limit): array
    {
        // Triggered policies are intentionally included: the watcher re-evaluates them
        // once their cooldown elapses so a recovered metric can resolve the breach.
        $rows = $this->connection->createQueryBuilder()
            ->select('*')
            ->from($this->table)
            ->where('enabled = true AND (last_evaluated_at IS NULL OR last_evaluated_at < :before)')
            ->setParameter('before', $before->format('Y-m-d H:i:s'))
            ->setMaxResults($limit)
            ->orderBy('last_evaluated_at', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function delete(string $id): void
    {
        $this->connection->delete($this->table, ['id' => $id]);
    }

    private function toRow(GuardrailPolicy $policy): array
    {
        return [
            'id'                      => $policy->id,
            'flag_name'               => $policy->flagName,
            'project_id'              => $policy->projectId,
            'environment'             => $policy->environment,
            'status'                  => $policy->status,
            'action'                  => $policy->action->value,
            'pause_ramp_target_pct'   => $policy->pauseRampTargetPct,
            'consecutive_windows'     => $policy->consecutiveWindows,
            'window_seconds'          => $policy->windowSeconds,
            'cooldown_seconds'        => $policy->cooldownSeconds,
            'enabled'                 => $policy->enabled ? 1 : 0,
            'consecutive_breach_count' => $policy->consecutiveBreachCount,
            'conditions'              => json_encode(
                array_map(fn(GuardrailCondition $c) => $c->toArray(), $policy->conditions),
                JSON_THROW_ON_ERROR,
            ),
            'last_evaluated_at'       => $policy->lastEvaluatedAt?->format('Y-m-d H:i:s'),
            'triggered_at'            => $policy->triggeredAt?->format('Y-m-d H:i:s'),
            'resolved_at'             => $policy->resolvedAt?->format('Y-m-d H:i:s'),
            'created_at'              => $policy->createdAt->format('Y-m-d H:i:s'),
            'created_by'              => $policy->createdBy,
            'ack_required'            => $policy->ackRequired ? 1 : 0,
        ];
    }

    private function hydrate(array $row): GuardrailPolicy
    {
        return GuardrailPolicy::fromArray([
            'id'                      => $row['id'],
            'flag_name'               => $row['flag_name'],
            'project_id'              => $row['project_id'],
            'environment'             => $row['environment'],
            'status'                  => $row['status'],
            'action'                  => $row['action'],
            'pause_ramp_target_pct'   => $row['pause_ramp_target_pct'],
            'consecutive_windows'     => $row['consecutive_windows'],
            'window_seconds'          => $row['window_seconds'],
            'cooldown_seconds'        => $row['cooldown_seconds'],
            'enabled'                 => $row['enabled'],
            'consecutive_breach_count' => $row['consecutive_breach_count'],
            'conditions'              => $row['conditions'],
            'last_evaluated_at'       => $row['last_evaluated_at'],
            'triggered_at'            => $row['triggered_at'],
            'resolved_at'             => $row['resolved_at'],
            'created_at'              => $row['created_at'],
            'created_by'              => $row['created_by'],
            'ack_required'            => $row['ack_required'] ?? false,
        ]);
    }
}
