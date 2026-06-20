<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Attribute;

/**
 * Marks a class as a service factory container.
 *
 * A #[ServiceProvider] class groups one or more #[Provides] factory methods.
 * The class itself is registered as a regular autowired service — its own
 * constructor dependencies are resolved normally by Symfony.
 *
 * Usage:
 *
 *   #[ServiceProvider]
 *   final class LockoutServiceProvider
 *   {
 *       #[Provides('platform_staff.lockout_manager')]
 *       public function manager(
 *           #[InjectService('platform_staff.lockout_store')] RedisLockoutStore $store,
 *       ): LockoutManager {
 *           return new LockoutManager($store, ...);
 *       }
 *   }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ServiceProvider {}
