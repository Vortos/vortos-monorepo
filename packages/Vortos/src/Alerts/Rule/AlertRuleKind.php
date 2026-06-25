<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule;

enum AlertRuleKind: string
{
    case ErrorRate = 'error_rate';
    case P95Latency = 'p95_latency';
    case SloBurn = 'slo_burn';
    case HealthProbeFailing = 'health_probe_failing';
    case ResourceExhaustion = 'resource_exhaustion';
    case CertNearExpiry = 'cert_near_expiry';
    case BackupFailed = 'backup_failed';
    case QueueLag = 'queue_lag';
}
