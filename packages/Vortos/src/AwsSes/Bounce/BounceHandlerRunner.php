<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Bounce;

use Psr\Log\LoggerInterface;
use Vortos\AwsSes\Contract\BounceHandlerInterface;
use Vortos\AwsSes\Webhook\BounceNotification;

/**
 * Dispatches a bounce notification to all registered handlers in order.
 *
 * AutoSuppressionBounceHandler always runs first (injected at index 0).
 * User-registered handlers follow in the order they were tagged.
 *
 * One failing handler never blocks others — all failures are caught, logged,
 * and swallowed so that every handler gets a chance to run.
 */
final class BounceHandlerRunner
{
    /** @param BounceHandlerInterface[] $handlers */
    public function __construct(
        private readonly array $handlers,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(BounceNotification $notification): void
    {
        foreach ($this->handlers as $handler) {
            try {
                $handler->handle($notification);
            } catch (\Throwable $e) {
                $this->logger->error('ses.bounce: handler failed', [
                    'handler' => $handler::class,
                    'address' => $notification->recipient()->address(),
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
