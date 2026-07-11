<?php

declare(strict_types=1);

namespace Vortos\Audit\Observability;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

/**
 * Declares audit metric instruments for the global registry. Labels are deliberately
 * low-cardinality (scope is a 2-value enum; nothing is labelled by tenant or actor) so
 * the audit subsystem can never blow up the metrics label space.
 */
final class AuditMetricDefinitions implements MetricDefinitionProviderInterface
{
    public function definitions(): array
    {
        return [
            MetricDefinition::counter(
                'vortos_audit_events_ingested_total',
                'Audit events appended to a chain, by scope.',
                ['scope'],
            ),
            MetricDefinition::counter(
                'vortos_audit_ingest_duplicates_total',
                'Audit events skipped as duplicate deliveries.',
                [],
            ),
            MetricDefinition::counter(
                'vortos_audit_ingest_failures_total',
                'Audit events that failed to enqueue for ingestion (dropped or blocked).',
                [],
            ),
            MetricDefinition::counter(
                'vortos_audit_verify_failures_total',
                'Audit chain verification runs that found a break.',
                [],
            ),
            MetricDefinition::counter(
                'vortos_audit_archived_total',
                'Audit records written to cold storage by retention.',
                [],
            ),
            MetricDefinition::counter(
                'vortos_audit_purged_total',
                'Audit records deleted from the hot table by retention (after archive).',
                [],
            ),
        ];
    }
}
