<?php

declare(strict_types=1);

namespace Vortos\Metrics\Attribute;

/**
 * Suppresses metrics injection for the annotated repository class.
 *
 * When placed on a MongoDB read repository class, MongoReadRepositoryAutowirePass
 * marks the MongoStore with 'vortos.skip_metrics' so MongoMetricsCompilerPass
 * skips FrameworkTelemetry injection for that specific store.
 *
 * Use for high-volume collections where per-operation metrics recording
 * adds meaningful overhead and the data is not actionable (e.g. audit logs,
 * event store reads, debug collections).
 *
 * Note: For DBAL/ORM repositories, metrics are applied at the connection
 * middleware level and cannot be disabled per-repository.
 * Use vortos.metrics.disabled_modules: ['persistence'] to disable globally.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class DisableMetrics {}
