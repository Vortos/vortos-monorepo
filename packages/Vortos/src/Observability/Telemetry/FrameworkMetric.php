<?php

declare(strict_types=1);

namespace Vortos\Observability\Telemetry;

enum FrameworkMetric: string
{
    case HttpRequestsTotal = 'http_requests_total';
    case HttpRequestDurationMs = 'http_request_duration_ms';
    case HttpBlockedTotal = 'http_blocked_total';
    case CqrsCommandsTotal = 'cqrs_commands_total';
    case CqrsCommandFailuresTotal = 'cqrs_command_failures_total';
    case CqrsCommandDurationMs = 'cqrs_command_duration_ms';
    case CqrsQueriesTotal = 'cqrs_queries_total';
    case CqrsQueryFailuresTotal = 'cqrs_query_failures_total';
    case CqrsQueryDurationMs = 'cqrs_query_duration_ms';
    case MessagingEventsDispatchedTotal = 'messaging_events_dispatched_total';
    case MessagingEventFailuresTotal = 'messaging_event_failures_total';
    case MessagingEventDurationMs = 'messaging_event_duration_ms';
    case MessagingMessagesConsumedTotal = 'messaging_messages_consumed_total';
    case MessagingMessageRetriesTotal = 'messaging_message_retries_total';
    case MessagingMessageDurationMs = 'messaging_message_duration_ms';
    case OutboxBacklogSize = 'outbox_backlog_size';
    case OutboxOldestPendingAgeSeconds = 'outbox_oldest_pending_age_seconds';
    case DlqBacklogSize = 'dlq_backlog_size';
    case DlqOldestFailedAgeSeconds = 'dlq_oldest_failed_age_seconds';
    case CacheOperationsTotal = 'cache_operations_total';
    case DbQueriesTotal = 'db_queries_total';
    case DbQueryDurationMs = 'db_query_duration_ms';
    case SecurityEventsTotal = 'security_events_total';
    case RateLimitAllowedTotal = 'rate_limit_allowed_total';
    case RateLimitBlockedTotal = 'rate_limit_blocked_total';
    case QuotaAllowedTotal = 'quota_allowed_total';
    case QuotaBlockedTotal = 'quota_blocked_total';
    case QuotaConsumedTotal = 'quota_consumed_total';
    case FeatureAccessAllowedTotal = 'feature_access_allowed_total';
    case FeatureAccessDeniedTotal = 'feature_access_denied_total';
    case FeatureFlagEvaluationsTotal = 'feature_flag_evaluations_total';
}
