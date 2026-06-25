<?php

declare(strict_types=1);

namespace Vortos\Alerts\Notifier;

use InvalidArgumentException;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Capability\CapabilityKey;

/**
 * For `Critical` only: ordered multi-channel fallback (§3.6, improvement #4). If the
 * primary paging channel returns `Failed`, the next channel is tried; if every
 * channel fails, this returns a single `Failed` result whose reason names every
 * channel that failed — the caller (the dispatcher / {@see \Vortos\Alerts\Integration\Audit\AlertAuditRecorder})
 * turns total failure into a loud, audited `Critical` "page dropped" event. A
 * dropped page must be loud, never silent.
 */
final class FallbackNotifier implements NotifierInterface
{
    /** @param list<NotifierInterface> $channels ordered, primary first */
    public function __construct(
        private readonly array $channels,
    ) {
        if ($channels === []) {
            throw new InvalidArgumentException('FallbackNotifier requires at least one channel.');
        }
    }

    public function name(): string
    {
        return 'fallback';
    }

    public function notify(NotifierMessage $message): NotificationResult
    {
        $failures = [];

        foreach ($this->channels as $channel) {
            $result = $channel->notify($message);
            if ($result->isSuccess()) {
                return $result;
            }
            $failures[] = sprintf('%s: %s', $channel->name(), $result->reason ?? 'unknown failure');
        }

        return NotificationResult::failed($this->name(), 'all fallback channels failed: ' . implode('; ', $failures));
    }

    public function capabilities(): CapabilityDescriptor
    {
        $capabilities = [];
        foreach ($this->channels as $channel) {
            $descriptor = $channel->capabilities();
            foreach ($descriptor->toArray()['capabilities'] as $key => $supported) {
                /** @var CapabilityKey|string $key */
                $capabilities[$key] = ($capabilities[$key] ?? false) || $supported;
            }
        }

        return CapabilityDescriptor::create($capabilities);
    }
}
