<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\AuditAdmin\DependencyInjection\AuditAdminExtension;
use Vortos\AuditAdmin\Http\Controller\OrgAuditController;
use Vortos\AuditAdmin\Http\Controller\PlatformAuditController;
use Vortos\AuditAdmin\Http\Controller\PlatformAuditVerifyController;

final class AuditAdminExtensionTest extends TestCase
{
    private function load(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new AuditAdminExtension())->load([], $container);

        return $container;
    }

    public function test_every_registered_service_maps_to_a_real_class(): void
    {
        foreach ($this->load()->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if ($class === null || !str_starts_with($class, 'Vortos\\AuditAdmin')) {
                continue;
            }
            self::assertTrue(
                class_exists($class),
                "Service '{$id}' references missing class '{$class}' (likely a missing use-import).",
            );
        }
    }

    public function test_all_controllers_are_registered_public_and_tagged_as_api_controllers(): void
    {
        $container = $this->load();

        // Export controllers are wired by AuditExportControllerPass (needs AuditExportService),
        // not by load(), so only the read/verify controllers are expected here.
        $controllers = [
            PlatformAuditController::class,
            PlatformAuditVerifyController::class,
            OrgAuditController::class,
        ];

        foreach ($controllers as $controller) {
            self::assertTrue($container->hasDefinition($controller), "{$controller} not registered");
            $def = $container->getDefinition($controller);
            self::assertTrue($def->isPublic(), "{$controller} must be public for routing");
            self::assertTrue($def->hasTag('vortos.api.controller'), "{$controller} must carry the api.controller tag");
        }
    }
}
