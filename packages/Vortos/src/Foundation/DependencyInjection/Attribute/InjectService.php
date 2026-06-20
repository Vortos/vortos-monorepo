<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Attribute;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Injects a named service by its container ID into a constructor parameter.
 *
 * Use when two services share the same type and you need a specific one:
 *
 *   public function __construct(
 *       LockoutManager $defaultLockoutManager,
 *       #[InjectService('platform_staff.lockout_manager')]
 *       LockoutManager $platformStaffLockoutManager,
 *   ) {}
 *
 * No compiler pass needed — Symfony's AutowirePass processes #[Autowire]
 * subclasses on constructor parameters natively at compile time.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class InjectService extends Autowire
{
    public function __construct(string $serviceId)
    {
        parent::__construct(service: $serviceId);
    }
}
