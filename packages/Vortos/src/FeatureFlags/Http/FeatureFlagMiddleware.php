<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\FeatureFlags\Attribute\RequiresFlag;
use Vortos\FeatureFlags\Exception\FeatureNotAvailableException;
use Vortos\FeatureFlags\FlagRegistryInterface;

/**
 * Enforces #[RequiresFlag] on controllers.
 * Runs at priority 5 — after RouterListener (8) has set _controller,
 * same pattern as AuthMiddleware.
 */
final class FeatureFlagMiddleware implements EventSubscriberInterface
{
    /** @var array<string, string|null> class → flag name or null if no attribute */
    private array $cache = [];

    public function __construct(
        private readonly FlagRegistryInterface $registry,
        private readonly FlagContextResolverInterface $contextResolver,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 5]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request    = $event->getRequest();
        $controller = $request->attributes->get('_controller');

        if ($controller === null) {
            return;
        }

        $class = $this->extractClass($controller);

        if ($class === null || !class_exists($class)) {
            return;
        }

        $method   = is_array($controller) ? ($controller[1] ?? '__invoke') : '__invoke';
        $cacheKey = $class . '::' . $method;

        if (!array_key_exists($cacheKey, $this->cache)) {
            $this->cache[$cacheKey] = $this->resolveFlag($class, $method);
        }

        $flagName = $this->cache[$cacheKey];

        if ($flagName === null) {
            return;
        }

        $context = $this->contextResolver->resolve($request);

        if (!$this->registry->isEnabled($flagName, $context)) {
            throw new FeatureNotAvailableException($flagName);
        }
    }

    private function resolveFlag(string $class, string $method): ?string
    {
        try {
            $rc    = new \ReflectionClass($class);
            $attrs = $rc->getAttributes(RequiresFlag::class);

            if (empty($attrs) && $rc->hasMethod($method)) {
                $attrs = $rc->getMethod($method)->getAttributes(RequiresFlag::class);
            }

            return empty($attrs) ? null : $attrs[0]->newInstance()->flag;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractClass(mixed $controller): ?string
    {
        if (is_string($controller)) {
            return explode('::', $controller)[0];
        }

        if (is_array($controller)) {
            return is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
        }

        if (is_object($controller)) {
            return get_class($controller);
        }

        return null;
    }
}
