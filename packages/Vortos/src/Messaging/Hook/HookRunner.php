<?php

declare(strict_types=1);

namespace Vortos\Messaging\Hook;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Throwable;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Messaging\Hook\Exception\HookExecutionException;
use Vortos\Messaging\Hook\Attribute\BeforeHandler;
use Vortos\Messaging\Hook\Attribute\AfterHandler;
use Vortos\Messaging\Hook\HandlerOutcome;

/**
 * Executes registered hooks at the correct lifecycle moments.
 *
 * Producer-side hooks (BeforeDispatch, AfterDispatch, PreSend) receive the
 * domain EventEnvelope. Filter matching compares against the envelope's
 * payloadType (FQCN of the payload class).
 *
 * Consumer-side hooks (BeforeConsume, AfterConsume) continue to receive the
 * Symfony Messenger Envelope built by ConsumerRunner — they operate on
 * received messages, not on the producer-side domain envelope.
 *
 * Retrieves descriptors from HookRegistry, applies filters, resolves hook
 * instances from the scoped ServiceLocator, and invokes them. Each hook is
 * wrapped individually — one failing hook never prevents others from running.
 * Never throws publicly. All hook exceptions are caught and logged.
 */
final class HookRunner
{
    public function __construct(
        private HookRegistry $registry,
        private ServiceLocator $hookLocator,
        private LoggerInterface $logger,
    ) {}

    public function runBeforeDispatch(EventEnvelope $envelope): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::BEFORE_DISPATCH);

        foreach ($hooks as $hook) {
            if ($this->matchesEvent($hook, $envelope->payloadType)) {
                $this->invoke($hook, fn($hook) => $hook->__invoke($envelope));
            }
        }
    }

    public function runAfterDispatch(EventEnvelope $envelope, ?Throwable $throwable): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::AFTER_DISPATCH);

        foreach ($hooks as $hook) {
            if (!$this->matchesEvent($hook, $envelope->payloadType)) {
                continue;
            }

            if ($hook->onFailureOnly === true && $throwable === null) {
                continue;
            }

            $this->invoke($hook, fn($hook) => $hook->__invoke($envelope, $throwable));
        }
    }

    public function runPreSend(EventEnvelope $envelope, array &$headers): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::PRE_SEND);

        foreach ($hooks as $hook) {
            if ($this->matchesEvent($hook, $envelope->payloadType)) {
                $this->invoke(
                    $hook,
                    function ($hook) use ($envelope, &$headers) {
                        $hook->__invoke($envelope, $headers);
                    },
                );
            }
        }
    }

    public function runBeforeConsume(EventEnvelope $envelope, string $consumerName): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::BEFORE_CONSUME);

        foreach ($hooks as $hook) {
            if ($this->matchesConsume($hook, $envelope->payloadType, $consumerName)) {
                $this->invoke($hook, fn($hook) => $hook->__invoke($envelope, $consumerName));
            }
        }
    }

    public function runAfterConsume(EventEnvelope $envelope, string $consumerName, ?Throwable $throwable = null): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::AFTER_CONSUME);

        foreach ($hooks as $hook) {
            if (!$this->matchesConsume($hook, $envelope->payloadType, $consumerName)) {
                continue;
            }

            if ($hook->onFailureOnly === true && $throwable === null) {
                continue;
            }

            $this->invoke($hook, fn($hook) => $hook->__invoke($envelope, $consumerName, $throwable));
        }
    }

    public function runBeforeHandler(EventEnvelope $envelope, string $consumerName, string $handlerId): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::BEFORE_HANDLER);

        foreach ($hooks as $hook) {
            if ($this->matchesConsume($hook, $envelope->payloadType, $consumerName)) {
                $this->invoke($hook, fn($hook) => $hook->__invoke($envelope, $consumerName, $handlerId));
            }
        }
    }

    public function runAfterHandler(
        EventEnvelope  $envelope,
        string         $consumerName,
        string         $handlerId,
        HandlerOutcome $outcome,
        int            $attempts,
        float          $latencyMs,
        ?Throwable     $throwable = null,
    ): void {
        $hooks = $this->registry->getHooks(HookDescriptor::AFTER_HANDLER);

        foreach ($hooks as $hook) {
            if (!$this->matchesConsume($hook, $envelope->payloadType, $consumerName)) {
                continue;
            }

            // Empty `on` = all terminal outcomes; AttemptFailed is always opt-in.
            if ($hook->on === [] && $outcome === HandlerOutcome::AttemptFailed) {
                continue;
            }

            // Non-empty `on` = only fire for explicitly listed outcomes.
            if ($hook->on !== [] && !in_array($outcome, $hook->on, true)) {
                continue;
            }

            $this->invoke(
                $hook,
                fn($hook) => $hook->__invoke($envelope, $consumerName, $handlerId, $outcome, $attempts, $latencyMs, $throwable),
            );
        }
    }

    private function matchesEvent(HookDescriptor $descriptor, string $payloadType): bool
    {
        return $descriptor->eventFilter === null || $descriptor->eventFilter === $payloadType;
    }

    private function matchesConsume(HookDescriptor $descriptor, string $payloadType, string $consumerName): bool
    {
        return $this->matchesEvent($descriptor, $payloadType)
            && ($descriptor->consumerFilter === null || $descriptor->consumerFilter === $consumerName);
    }

    private function invoke(HookDescriptor $descriptor, callable $call): void
    {
        $hook = $this->hookLocator->get($descriptor->serviceId);

        try {
            $call($hook);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('Hook execution failed: %s [type=%s]', $descriptor->serviceId, $descriptor->hookType),
                [
                    'exception' => HookExecutionException::forHook(
                        $descriptor->serviceId,
                        $descriptor->hookType,
                        $e,
                    ),
                ],
            );
        }
    }
}
