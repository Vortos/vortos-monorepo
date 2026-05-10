<?php

declare(strict_types=1);

namespace Vortos\Security\Event;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Security\Contract\SecurityEventInterface;

/**
 * Dispatches security events to all registered observers.
 *
 * Automatically logs every event to the security Logger channel and increments
 * the corresponding Prometheus/StatsD counter when Metrics is available.
 *
 * Custom listeners can be registered via addListener() — typically wired by
 * the DI container using the #[AsSecurityEventListener] tag.
 *
 * ## Observability integration
 *
 * Logger and Metrics are both injected as nullable — when the Logger or Metrics
 * module is not installed (or is NoOp), dispatching events is a zero-overhead no-op.
 *
 * ## Security event metric names
 *
 *   security_csrf_violations_total
 *   security_ip_denied_total
 *   security_signature_failures_total
 *   security_suspicious_requests_total
 */
final class SecurityEventDispatcher
{
    /** @var array<string, list<callable>> event name → listeners */
    private array $listeners = [];

    public function __construct(
        private readonly ?LoggerInterface  $logger,
        private readonly ?MetricsInterface $metrics,
    ) {}

    public function dispatch(SecurityEventInterface $event): void
    {
        $this->log($event);
        $this->count($event);

        foreach ($this->listeners[$event->eventName()] ?? [] as $listener) {
            $listener($event);
        }

        foreach ($this->listeners['*'] ?? [] as $listener) {
            $listener($event);
        }
    }

    public function addListener(string $eventName, callable $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    private function log(SecurityEventInterface $event): void
    {
        if ($this->logger === null) {
            return;
        }

        $level = match ($event->eventName()) {
            'security.ip_denied'          => LogLevel::WARNING,
            'security.csrf_violation'     => LogLevel::WARNING,
            'security.signature_invalid'  => LogLevel::WARNING,
            'security.suspicious_request' => LogLevel::ERROR,
            default                       => LogLevel::INFO,
        };

        $this->logger->log($level, '[security] ' . $event->eventName(), $event->context());
    }

    private function count(SecurityEventInterface $event): void
    {
        if ($this->metrics === null) {
            return;
        }

        $metricName = match ($event->eventName()) {
            'security.csrf_violation'     => 'security_csrf_violations_total',
            'security.ip_denied'          => 'security_ip_denied_total',
            'security.signature_invalid'  => 'security_signature_failures_total',
            'security.suspicious_request' => 'security_suspicious_requests_total',
            default                       => null,
        };

        if ($metricName !== null) {
            $this->metrics->increment($metricName);
        }
    }
}
