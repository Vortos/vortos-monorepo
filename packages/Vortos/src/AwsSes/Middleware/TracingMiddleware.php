<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Middleware;

use Vortos\AwsSes\Attribute\AsEmailMiddleware;
use Vortos\AwsSes\Config\AwsSesObservabilitySection;
use Vortos\AwsSes\Contract\EmailMiddlewareInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;
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
    /** @param string[] $disabledSections */
    public function __construct(
        private readonly TracingInterface $tracer,
        private readonly array $disabledSections = [],
    ) {}

    public function process(Email $email, callable $next): SentEmail
    {
        if (in_array(AwsSesObservabilitySection::Send->value, $this->disabledSections, true)) {
            return $next($email);
        }

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
