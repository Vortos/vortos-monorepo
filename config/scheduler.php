<?php

declare(strict_types=1);

use Vortos\Scheduler\DependencyInjection\VortosSchedulerConfig;

return static function (VortosSchedulerConfig $config): void {
    // Defaults are env-driven — override here when needed.

    // Run retention (vortos_scheduler_runs auto-prune). 0 disables it entirely:
    // $config->runRetentionDays(90);

    // Lease driver: 'sql', 'redis', 'postgres-advisory', or 'in-memory'.
    // $config->leaseDriver('postgres-advisory');

    // Daemon horizontal scaling:
    // $config->shardCount(4);

    // Fire-queue consumer tuning:
    // $config->consumeBatchSize(200)->consumePollIntervalSec(1);

    // Audit hash-chain signing key (empty = audit projection disabled):
    // $config->auditHmacKey($_ENV['SCHEDULER_AUDIT_HMAC_KEY'] ?? '');
};
