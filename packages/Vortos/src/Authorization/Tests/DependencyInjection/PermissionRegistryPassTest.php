<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\Authorization\DependencyInjection\Compiler\PermissionRegistryPass;
use Vortos\Authorization\Middleware\ControllerPermissionMap;
use Vortos\Authorization\Permission\AbstractPermissionCatalog;
use Vortos\Authorization\Permission\PermissionRegistry;

// ── Fixture catalogs (named so the pass can reflect them) ────────────────────

#[PermissionCatalog(resource: 'flags', group: 'Feature Flags')]
final class FwFlagsCatalogFixture extends AbstractPermissionCatalog
{
    public const WRITE_ANY = 'write.any';

    public static function meta(): array
    {
        return [self::WRITE_ANY => self::describe('Write any flag', dangerous: true)];
    }
}

#[PermissionCatalog(resource: 'platform', group: 'Platform')]
final class AppPlatformCatalogFixture extends AbstractPermissionCatalog
{
    public const ORG_VIEW = 'org.view';

    public static function meta(): array
    {
        return [self::ORG_VIEW => self::describe('View orgs')];
    }

    public static function grants(): array
    {
        // Cross-catalog grant: a platform-owned role gets a flags-owned permission.
        return ['superadmin' => [self::ORG_VIEW, 'flags.write.any']];
    }
}

#[PermissionCatalog(resource: 'platform', group: 'Platform')]
final class AppBadGrantCatalogFixture extends AbstractPermissionCatalog
{
    public static function grants(): array
    {
        return ['superadmin' => ['flags.does_not_exist.any']];
    }
}

final class PermissionRegistryPassTest extends TestCase
{
    /**
     * The regression this pass fixes: an app catalog that grants a framework-owned
     * permission must resolve even when the app catalog is discovered BEFORE the
     * framework catalog that declares it. We register the app catalog first on purpose.
     */
    public function test_cross_catalog_grant_resolves_regardless_of_discovery_order(): void
    {
        $container = $this->containerWith([
            AppPlatformCatalogFixture::class => 'platform',
            FwFlagsCatalogFixture::class => 'flags',
        ]);

        (new PermissionRegistryPass())->process($container);

        $registry = $container->getDefinition(PermissionRegistry::class);
        /** @var array<string,mixed> $permissions */
        $permissions = $registry->getArgument('$permissions');
        /** @var array<string,string[]> $grants */
        $grants = $registry->getArgument('$defaultGrants');

        $this->assertArrayHasKey('flags.write.any', $permissions);
        $this->assertArrayHasKey('platform.org.view', $permissions);
        $this->assertContains('flags.write.any', $grants['superadmin']);
        $this->assertContains('platform.org.view', $grants['superadmin']);
    }

    public function test_granting_a_truly_unknown_permission_still_throws(): void
    {
        $container = $this->containerWith([AppBadGrantCatalogFixture::class => 'platform']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/grants unknown permission/');

        (new PermissionRegistryPass())->process($container);
    }

    /** @param array<class-string,string> $catalogs class => resource (registration order preserved) */
    private function containerWith(array $catalogs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(PermissionRegistry::class, new Definition(PermissionRegistry::class));
        $container->setDefinition(ControllerPermissionMap::class, new Definition(ControllerPermissionMap::class));

        foreach ($catalogs as $class => $resource) {
            $def = new Definition($class);
            $def->addTag('vortos.permission_catalog', ['resource' => $resource]);
            $container->setDefinition($class, $def);
        }

        return $container;
    }
}
