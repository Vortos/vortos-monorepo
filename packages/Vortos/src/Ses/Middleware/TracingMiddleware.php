<?php

declare(strict_types=1);

namespace Vortos\Ses\Middleware;

use Vortos\Ses\Attribute\AsEmailMiddleware;
use Vortos\Ses\Contract\EmailMiddlewareInterface;
use Vortos\Ses\ValueObject\Email;
use Vortos\Ses\ValueObject\SentEmail;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Wraps each email send in a distributed tracing span.
 *
 * Priority 800 — runs after logging (900) so the span covers the full
 * send pipeline including downstream middleware and the driver call.
 *
 * Attributes recorded on the span:
 *   - ses.recipient_count, ses.subject, ses.has_attachments
 *   - ses.driver (ses | log | null) and ses.message_id on success
 *   - ses.error_class on failure
 *
 * The default TracingInterface is NoOpTracer — zero overhead unless
 * OpenTelemetryTracer is wired up.
 */
#[AsEmailMiddleware(priority: 800)]
final class TracingMiddleware implements EmailMiddlewareInterface
{
    public function __construct(private readonly TracingInterface $tracer) {}

    public function process(Email $email, callable $next): SentEmail
    {
        $span = $this->tracer->startSpan('ses.send', [
            'ses.recipient_count'  => count($email->getTo()) + count($email->getCc()) + count($email->getBcc()),
            'ses.subject'          => $email->getSubject() ?? '',
            'ses.has_attachments'  => count($email->getAttachments()) > 0,
        ]);

        try {
            $result = $next($email);

            $span->addAttribute('ses.driver',     $result->driver());
            $span->addAttribute('ses.message_id', $result->messageId());
            $span->setStatus('ok');

            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->end();
        }
    }
}
