<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Middleware;

use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Attribute\AsEmailMiddleware;
use Vortos\AwsSes\Contract\EmailMiddlewareInterface;
use Vortos\AwsSes\Contract\EmailSendObserverInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Fires registered observers before and after each email send.
 *
 * Priority 700 — runs after tracing (800) and logging (900), giving observers
 * access to the full context that those middlewares add. Observer failures are
 * swallowed and logged — they must never interrupt email delivery.
 *
 * Register observers by tagging services with 'vortos_aws_ses.send_observer'.
 */
#[AsEmailMiddleware(priority: 700)]
final class HookMiddleware implements EmailMiddlewareInterface
{
    /** @param EmailSendObserverInterface[] $observers */
    public function __construct(
        private readonly array $observers,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(Email $email, callable $next): SentEmail
    {
        foreach ($this->observers as $observer) {
            $this->safeCall(fn() => $observer->beforeSend($email), $observer, 'beforeSend');
        }

        try {
            $result = $next($email);
        } catch (\Throwable $e) {
            foreach ($this->observers as $observer) {
                $this->safeCall(fn() => $observer->onSendError($email, $e), $observer, 'onSendError');
            }
            throw $e;
        }

        foreach ($this->observers as $observer) {
            $this->safeCall(fn() => $observer->afterSend($email, $result), $observer, 'afterSend');
        }

        return $result;
    }

    private function safeCall(callable $fn, EmailSendObserverInterface $observer, string $method): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->logger->warning('ses.hook: observer error', [
                'observer' => $observer::class,
                'method'   => $method,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
