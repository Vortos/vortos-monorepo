<?php

declare(strict_types=1);

namespace Vortos\Foundation\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Domain\Attribute\AsDomainService;
use Vortos\Foundation\DependencyInjection\Attribute\DefaultImpl;
use Vortos\Foundation\DependencyInjection\Compiler\DefaultImplCompilerPass;
use Vortos\Foundation\DependencyInjection\Compiler\DomainServiceCompilerPass;

interface DomainServiceContract {}

#[AsDomainService]
final class AttributedDomainService {}

#[AsDomainService]
final class RootDomainService {}

final class PlainDomainClass {}

#[AsDomainService]
final class AttributedOutsideDomainService {}

#[AsDomainService]
#[DefaultImpl]
final class DefaultDomainService implements DomainServiceContract {}

#[AsDomainService]
final class ManuallyRegisteredDomainService {}

final class DomainServiceCompilerPassTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir() . '/vortos_domain_service_' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/src/User/Domain/Nested', 0777, true);
        mkdir($this->projectDir . '/src/User/Application', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);

        parent::tearDown();
    }

    public function test_registers_attributed_class_inside_domain_folder(): void
    {
        $this->writeClassFile('src/User/Domain/Nested/AttributedDomainService.php', AttributedDomainService::class);

        $container = $this->container();

        (new DomainServiceCompilerPass())->process($container);

        $this->assertTrue($container->hasDefinition(AttributedDomainService::class));

        $definition = $container->getDefinition(AttributedDomainService::class);
        $this->assertSame(AttributedDomainService::class, $definition->getClass());
        $this->assertTrue($definition->isAutowired());
        $this->assertFalse($definition->isPublic());
        $this->assertTrue($definition->hasTag('vortos.domain_service'));
    }

    public function test_registers_attributed_class_inside_root_domain_folder(): void
    {
        mkdir($this->projectDir . '/src/Domain', 0777, true);
        $this->writeClassFile('src/Domain/RootDomainService.php', RootDomainService::class);

        $container = $this->container();

        (new DomainServiceCompilerPass())->process($container);

        $this->assertTrue($container->hasDefinition(RootDomainService::class));
        $this->assertTrue($container->getDefinition(RootDomainService::class)->hasTag('vortos.domain_service'));
    }

    public function test_ignores_plain_domain_classes_without_attribute(): void
    {
        $this->writeClassFile('src/User/Domain/PlainDomainClass.php', PlainDomainClass::class);

        $container = $this->container();

        (new DomainServiceCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(PlainDomainClass::class));
    }

    public function test_ignores_attributed_classes_outside_domain_folder(): void
    {
        $this->writeClassFile('src/User/Application/AttributedOutsideDomainService.php', AttributedOutsideDomainService::class);

        $container = $this->container();

        (new DomainServiceCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(AttributedOutsideDomainService::class));
    }

    public function test_preserves_existing_manual_definition(): void
    {
        $this->writeClassFile('src/User/Domain/ManuallyRegisteredDomainService.php', ManuallyRegisteredDomainService::class);

        $container = $this->container();
        $container->setDefinition(
            ManuallyRegisteredDomainService::class,
            (new Definition(ManuallyRegisteredDomainService::class))
                ->setPublic(true)
                ->setAutowired(false),
        );

        (new DomainServiceCompilerPass())->process($container);

        $definition = $container->getDefinition(ManuallyRegisteredDomainService::class);
        $this->assertTrue($definition->isPublic());
        $this->assertFalse($definition->isAutowired());
        $this->assertTrue($definition->hasTag('vortos.domain_service'));
    }

    public function test_preserves_existing_manual_definition_with_custom_service_id(): void
    {
        $this->writeClassFile('src/User/Domain/ManuallyRegisteredDomainService.php', ManuallyRegisteredDomainService::class);

        $container = $this->container();
        $container->setDefinition(
            'app.manual_domain_service',
            (new Definition(ManuallyRegisteredDomainService::class))
                ->setPublic(true)
                ->setAutowired(false),
        );

        (new DomainServiceCompilerPass())->process($container);

        $this->assertFalse($container->hasDefinition(ManuallyRegisteredDomainService::class));

        $definition = $container->getDefinition('app.manual_domain_service');
        $this->assertTrue($definition->isPublic());
        $this->assertFalse($definition->isAutowired());
        $this->assertTrue($definition->hasTag('vortos.domain_service'));
    }

    public function test_forwards_default_impl_tag_for_aliasing_pass(): void
    {
        $this->writeClassFile('src/User/Domain/DefaultDomainService.php', DefaultDomainService::class);

        $container = $this->container();

        (new DomainServiceCompilerPass())->process($container);

        $this->assertTrue($container->getDefinition(DefaultDomainService::class)->hasTag('vortos.default_impl'));

        (new DefaultImplCompilerPass())->process($container);

        $this->assertTrue($container->hasAlias(DomainServiceContract::class));
        $this->assertSame(DefaultDomainService::class, (string) $container->getAlias(DomainServiceContract::class));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->projectDir);

        return $container;
    }

    private function writeClassFile(string $relativePath, string $class): void
    {
        $path = $this->projectDir . '/' . $relativePath;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $lastSlash = strrpos($class, '\\');
        $namespace = $lastSlash === false ? '' : substr($class, 0, $lastSlash);
        $shortName = $lastSlash === false ? $class : substr($class, $lastSlash + 1);

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

final class {$shortName} {}
PHP);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
