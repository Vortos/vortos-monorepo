<?php

declare(strict_types=1);

namespace Vortos\Persistence\Tests\Access;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Persistence\Access\DatabaseRoleModel;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;
use Vortos\Persistence\DependencyInjection\PersistenceExtension;

/**
 * The declaration reaches the container as a real service — from config/persistence.php and from the per-environment
 * override — and an installation that declares nothing gets a service that says so rather than no service.
 */
final class DeclaredDatabaseRolesWiringTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-roles-wiring-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config/prod', 0o777, true);
        file_put_contents($this->dir . '/config/persistence.php', "<?php\nreturn static function (\\Vortos\\Persistence\\DependencyInjection\\VortosPersistenceConfig \$c): void { \$c->frameworkTableMode('schema'); };\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/prod/persistence.php');
        @unlink($this->dir . '/config/persistence.php');
        @rmdir($this->dir . '/config/prod');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    private function load(string $env): DeclaredDatabaseRoles
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->dir);
        $container->setParameter('kernel.env', $env);
        (new PersistenceExtension())->load([], $container);

        $definition = $container->getDefinition(DeclaredDatabaseRoles::class);
        $class = (string) $definition->getClass();
        self::assertTrue(class_exists($class), sprintf('registered service class %s does not exist', $class));

        return new $class(...array_values($definition->getArguments()));
    }

    public function test_an_environment_override_declares_the_model(): void
    {
        file_put_contents($this->dir . '/config/prod/persistence.php', <<<'PHP'
            <?php
            use Vortos\Persistence\Access\{DatabaseRoleModel, DatabaseRoleName};
            return static function (\Vortos\Persistence\DependencyInjection\VortosPersistenceConfig $c): void {
                $c->databaseRoles(new DatabaseRoleModel('app', ['public'], new DatabaseRoleName('boot'), new DatabaseRoleName('app_owner'), new DatabaseRoleName('app_runtime'), null, []));
            };
            PHP);

        $model = $this->load('prod')->model();

        self::assertInstanceOf(DatabaseRoleModel::class, $model);
        self::assertSame('app_runtime', $model->runtime->value);
        self::assertNull($this->load('dev')->model());
    }
}
