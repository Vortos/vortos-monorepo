<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Hook;

use Fortizan\Tekton\Messaging\Contract\DomainEventInterface;
use Fortizan\Tekton\Messaging\Hook\Exception\HookExecutionException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Throwable;

/**
 * Executes registered hooks at the correct lifecycle moments.
 *
 * Retrieves descriptors from HookRegistry, applies event and consumer
 * filters, resolves hook instances from the scoped ServiceLocator,
 * and invokes handle() on each. Each hook invocation is wrapped
 * individually — one failing hook never prevents others from running.
 *
 * Never throws publicly. All hook exceptions are caught and logged.
 */
final class HookRunner
{
    public function __construct(
        private HookRegistry $registry,
        private ServiceLocator $hookLocator,
        private LoggerInterface $logger
    ) {}

    public function runBeforeDispatch(DomainEventInterface $event): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::BEFORE_DISPATCH);

        $eventClass = get_class($event);

        foreach ($hooks as $hook) {
            if ($this->matchesEvent($hook, $eventClass)) {
                $this->invoke(
                    $hook,
                    fn($hook) => $hook->handle($event)
                );
            }
        }
    }

    public function runAfterDispatch(DomainEventInterface $event, ?Throwable $throwable): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::AFTER_DISPATCH);

        $eventClass = get_class($event);

        foreach ($hooks as $hook) {
            if ($this->matchesEvent($hook, $eventClass)) {

                if ($hook->onFailureOnly === true && $throwable === null) {
                    continue;
                }
                $this->invoke(
                    $hook,
                    fn($hook) => $hook->handle($event, $throwable)
                );
            }
        }
    }

    public function runPreSend(DomainEventInterface $event, array &$headers): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::PRE_SEND);

        $eventClass = get_class($event);

        foreach ($hooks as $hook) {
            if ($this->matchesEvent($hook, $eventClass)) {
                $this->invoke(
                    $hook,
                    function ($hook) use ($event, &$headers) {
                        $hook->handle($event, $headers);
                    }
                );
            }
        }
    }

    public function runBeforeConsume(Envelope $envelope, string $consumerName): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::BEFORE_CONSUME);

        $eventClass = get_class($envelope->getMessage());

        foreach ($hooks as $hook) {
            if ($this->matchesConsume($hook, $eventClass, $consumerName)) {
                $this->invoke(
                    $hook,
                    fn($hook) => $hook->handle($envelope, $consumerName)
                );
            }
        }
    }

    public function runAfterConsume(Envelope $envelope, string $consumerName, ?Throwable $throwable = null): void
    {
        $hooks = $this->registry->getHooks(HookDescriptor::AFTER_CONSUME);

        $eventClass = get_class($envelope->getMessage());

        foreach ($hooks as $hook) {
            if ($this->matchesConsume($hook, $eventClass, $consumerName)) {

                if ($hook->onFailureOnly === true && $throwable === null) {
                    continue;
                }

                $this->invoke(
                    $hook,
                    fn($hook) => $hook->handle($envelope, $consumerName, $throwable)
                );
            }
        }
    }

    private function matchesEvent(HookDescriptor $descriptor, string $eventClass): bool
    {
        return $descriptor->eventFilter === null || $descriptor->eventFilter === $eventClass;
    }

    private function matchesConsume(HookDescriptor $descriptor, string $eventClass, string $consumerName): bool
    {
        return $this->matchesEvent($descriptor, $eventClass)
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
                        $e
                    )
                ]
            );
        }
    }
}
