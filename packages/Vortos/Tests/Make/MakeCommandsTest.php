<?php

declare(strict_types=1);

namespace Vortos\Tests\Make;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Foundation\Module\ModulePathResolver;
use Vortos\Make\Command\MakeAggregateCommand;
use Vortos\Make\Command\MakeContextCommand;
use Vortos\Make\Command\MakeDomainErrorCommand;
use Vortos\Make\Command\MakeDomainEventCommand;
use Vortos\Make\Command\MakeDomainServiceCommand;
use Vortos\Make\Command\MakeEntityCommand;
use Vortos\Make\Command\MakeHookCommand;
use Vortos\Make\Command\MakeValueObjectCommand;
use Vortos\Make\Engine\GeneratorEngine;
use Vortos\Make\Scanner\StubScanner;

final class MakeCommandsTest extends TestCase
{
    private string $projectDir;
    private GeneratorEngine $engine;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos-make-test-' . uniqid();
        mkdir($this->projectDir . '/src', 0755, true);

        $resolver     = new ModulePathResolver($this->findProjectRoot());
        $scanner      = new StubScanner($resolver, $this->projectDir);
        $this->engine = new GeneratorEngine($scanner, $this->projectDir);
    }

    private function findProjectRoot(): string
    {
        $dir = __DIR__;
        while ($dir !== \DIRECTORY_SEPARATOR) {
            if (file_exists($dir . '/vendor/composer/installed.json')) {
                return $dir;
            }
            $dir = dirname($dir);
        }
        throw new \RuntimeException('Cannot locate project root (no vendor/composer/installed.json found)');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->projectDir);
    }

    // ── make:aggregate ────────────────────────────────────────────────────────

    public function test_make_aggregate_generates_three_files(): void
    {
        $tester = new CommandTester(new MakeAggregateCommand($this->engine));
        $tester->execute(['name' => 'Order', '--context' => 'Order', '--no-orm' => true]);

        $this->assertFileExists($this->src('Order/Domain/Order/Order.php'));
        $this->assertFileExists($this->src('Order/Domain/Order/ValueObject/OrderId.php'));
        $this->assertFileExists($this->src('Order/Domain/Order/Repository/OrderRepositoryInterface.php'));
    }

    public function test_make_aggregate_id_is_inside_value_object_folder(): void
    {
        $tester = new CommandTester(new MakeAggregateCommand($this->engine));
        $tester->execute(['name' => 'Order', '--context' => 'Order', '--no-orm' => true]);

        $this->assertFileExists($this->src('Order/Domain/Order/ValueObject/OrderId.php'));
        $this->assertFileDoesNotExist($this->src('Order/Domain/Order/OrderId.php'));
    }

    public function test_make_aggregate_id_namespace_is_value_object(): void
    {
        $tester = new CommandTester(new MakeAggregateCommand($this->engine));
        $tester->execute(['name' => 'Order', '--context' => 'Order', '--no-orm' => true]);

        $content = file_get_contents($this->src('Order/Domain/Order/ValueObject/OrderId.php'));
        $this->assertStringContainsString('namespace App\Order\Domain\Order\ValueObject;', $content);
    }

    public function test_make_aggregate_no_orm_generates_plain_aggregate_root(): void
    {
        $tester = new CommandTester(new MakeAggregateCommand($this->engine));
        $tester->execute(['name' => 'Order', '--context' => 'Order', '--no-orm' => true]);

        $content = file_get_contents($this->src('Order/Domain/Order/Order.php'));
        $this->assertStringContainsString('extends AggregateRoot', $content);
        $this->assertStringNotContainsString('#[ORM\\', $content);
        $this->assertStringNotContainsString('ORM\Entity', $content);
    }

    public function test_make_aggregate_no_orm_namespace_is_correct(): void
    {
        $tester = new CommandTester(new MakeAggregateCommand($this->engine));
        $tester->execute(['name' => 'Order', '--context' => 'Order', '--no-orm' => true]);

        $content = file_get_contents($this->src('Order/Domain/Order/Order.php'));
        $this->assertStringContainsString('namespace App\Order\Domain\Order;', $content);
    }

    public function test_make_aggregate_repository_interface_namespace_is_correct(): void
    {
        $tester = new CommandTester(new MakeAggregateCommand($this->engine));
        $tester->execute(['name' => 'Order', '--context' => 'Order', '--no-orm' => true]);

        $content = file_get_contents($this->src('Order/Domain/Order/Repository/OrderRepositoryInterface.php'));
        $this->assertStringContainsString('namespace App\Order\Domain\Order\Repository;', $content);
    }

    public function test_make_aggregate_orm_mode_generates_doctrine_annotations(): void
    {
        if (!class_exists(\Vortos\PersistenceOrm\Aggregate\AggregateRoot::class)) {
            $this->markTestSkipped('vortos/persistence-orm not installed');
        }

        $tester = new CommandTester(new MakeAggregateCommand($this->engine));
        $tester->execute(['name' => 'Order', '--context' => 'Order']);

        $content = file_get_contents($this->src('Order/Domain/Order/Order.php'));
        $this->assertStringContainsString('ORM\Entity', $content);
        $this->assertStringContainsString('ORM\Table', $content);
        $this->assertStringContainsString('extends AggregateRoot', $content);
    }

    public function test_make_aggregate_context_defaults_to_name(): void
    {
        $tester = new CommandTester(new MakeAggregateCommand($this->engine));
        $tester->execute(['name' => 'Order', '--no-orm' => true]);

        $this->assertFileExists($this->src('Order/Domain/Order/Order.php'));
    }

    // ── make:entity ───────────────────────────────────────────────────────────

    public function test_make_entity_generates_entity_in_entity_folder(): void
    {
        $tester = new CommandTester(new MakeEntityCommand($this->engine));
        $tester->execute(['name' => 'OrderLine', '--context' => 'Order', '--aggregate' => 'Order', '--no-orm' => true]);

        $this->assertFileExists($this->src('Order/Domain/Order/Entity/OrderLine.php'));
    }

    public function test_make_entity_generates_id_in_value_object_folder(): void
    {
        $tester = new CommandTester(new MakeEntityCommand($this->engine));
        $tester->execute(['name' => 'OrderLine', '--context' => 'Order', '--aggregate' => 'Order', '--no-orm' => true]);

        $this->assertFileExists($this->src('Order/Domain/Order/ValueObject/OrderLineId.php'));
        $this->assertFileDoesNotExist($this->src('Order/Domain/Order/Entity/OrderLineId.php'));
    }

    public function test_make_entity_no_repository_interface_generated(): void
    {
        $tester = new CommandTester(new MakeEntityCommand($this->engine));
        $tester->execute(['name' => 'OrderLine', '--context' => 'Order', '--aggregate' => 'Order', '--no-orm' => true]);

        $this->assertFileDoesNotExist($this->src('Order/Domain/Order/Repository/OrderLineRepositoryInterface.php'));
    }

    public function test_make_entity_namespace_is_correct(): void
    {
        $tester = new CommandTester(new MakeEntityCommand($this->engine));
        $tester->execute(['name' => 'OrderLine', '--context' => 'Order', '--aggregate' => 'Order', '--no-orm' => true]);

        $content = file_get_contents($this->src('Order/Domain/Order/Entity/OrderLine.php'));
        $this->assertStringContainsString('namespace App\Order\Domain\Order\Entity;', $content);
    }

    public function test_make_entity_id_namespace_is_value_object(): void
    {
        $tester = new CommandTester(new MakeEntityCommand($this->engine));
        $tester->execute(['name' => 'OrderLine', '--context' => 'Order', '--aggregate' => 'Order', '--no-orm' => true]);

        $content = file_get_contents($this->src('Order/Domain/Order/ValueObject/OrderLineId.php'));
        $this->assertStringContainsString('namespace App\Order\Domain\Order\ValueObject;', $content);
    }

    public function test_make_entity_no_orm_generates_plain_class(): void
    {
        $tester = new CommandTester(new MakeEntityCommand($this->engine));
        $tester->execute(['name' => 'OrderLine', '--context' => 'Order', '--aggregate' => 'Order', '--no-orm' => true]);

        $content = file_get_contents($this->src('Order/Domain/Order/Entity/OrderLine.php'));
        $this->assertStringNotContainsString('AggregateRoot', $content);
        $this->assertStringNotContainsString('ORM\\', $content);
        $this->assertStringContainsString('final class OrderLine', $content);
    }

    public function test_make_entity_requires_aggregate(): void
    {
        $tester = new CommandTester(new MakeEntityCommand($this->engine));
        $tester->execute(['name' => 'OrderLine', '--context' => 'Order']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--aggregate', $tester->getDisplay());
    }

    public function test_make_entity_orm_mode_generates_child_entity_with_doctrine(): void
    {
        if (!class_exists(\Vortos\PersistenceOrm\Aggregate\AggregateRoot::class)) {
            $this->markTestSkipped('vortos/persistence-orm not installed');
        }

        $tester = new CommandTester(new MakeEntityCommand($this->engine));
        $tester->execute(['name' => 'OrderLine', '--context' => 'Order', '--aggregate' => 'Order']);

        $content = file_get_contents($this->src('Order/Domain/Order/Entity/OrderLine.php'));
        $this->assertStringContainsString('ORM\Entity', $content);
        $this->assertStringContainsString('ORM\Id', $content);
        $this->assertStringContainsString('ORM\Column', $content);
        $this->assertStringNotContainsString('AggregateRoot', $content);
    }

    // ── make:value-object ─────────────────────────────────────────────────────

    public function test_make_value_object_aggregate_generates_in_value_object_folder(): void
    {
        $tester = new CommandTester(new MakeValueObjectCommand($this->engine));
        $tester->execute(['name' => 'Duration', '--context' => 'Training', '--aggregate' => 'TrainingSession']);

        $this->assertFileExists($this->src('Training/Domain/TrainingSession/ValueObject/Duration.php'));
        $content = file_get_contents($this->src('Training/Domain/TrainingSession/ValueObject/Duration.php'));
        $this->assertStringContainsString('namespace App\Training\Domain\TrainingSession\ValueObject;', $content);
        $this->assertStringContainsString('extends ValueObject', $content);
    }

    public function test_make_value_object_shared_generates_in_shared_value_object_folder(): void
    {
        $tester = new CommandTester(new MakeValueObjectCommand($this->engine));
        $tester->execute(['name' => 'Email', '--context' => 'User', '--shared' => true]);

        $this->assertFileExists($this->src('User/Domain/Shared/ValueObject/Email.php'));
        $content = file_get_contents($this->src('User/Domain/Shared/ValueObject/Email.php'));
        $this->assertStringContainsString('namespace App\User\Domain\Shared\ValueObject;', $content);
    }

    public function test_make_value_object_fails_without_aggregate_or_shared(): void
    {
        $tester = new CommandTester(new MakeValueObjectCommand($this->engine));
        $tester->execute(['name' => 'Email', '--context' => 'User']);

        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('--aggregate', $output);
        $this->assertStringContainsString('--shared', $output);
    }

    public function test_make_value_object_fails_with_both_aggregate_and_shared(): void
    {
        $tester = new CommandTester(new MakeValueObjectCommand($this->engine));
        $tester->execute(['name' => 'Email', '--context' => 'User', '--aggregate' => 'User', '--shared' => true]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_make_value_object_embeddable_generates_doctrine_embeddable(): void
    {
        if (!class_exists(\Vortos\PersistenceOrm\Aggregate\AggregateRoot::class)) {
            $this->markTestSkipped('vortos/persistence-orm not installed');
        }

        $tester = new CommandTester(new MakeValueObjectCommand($this->engine));
        $tester->execute(['name' => 'Address', '--context' => 'User', '--aggregate' => 'User', '--embeddable' => true]);

        $this->assertFileExists($this->src('User/Domain/User/ValueObject/Address.php'));
        $content = file_get_contents($this->src('User/Domain/User/ValueObject/Address.php'));
        $this->assertStringContainsString('ORM\Embeddable', $content);
        $this->assertStringContainsString('ORM\Column', $content);
        $this->assertStringNotContainsString('readonly', $content);
    }

    public function test_make_value_object_embeddable_fails_without_orm_package(): void
    {
        if (class_exists(\Vortos\PersistenceOrm\Aggregate\AggregateRoot::class)) {
            $this->markTestSkipped('vortos/persistence-orm is installed — cannot test missing-package guard');
        }

        $tester = new CommandTester(new MakeValueObjectCommand($this->engine));
        $tester->execute(['name' => 'Address', '--context' => 'User', '--aggregate' => 'User', '--embeddable' => true]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    // ── make:domain-event ─────────────────────────────────────────────────────

    public function test_make_domain_event_generates_without_event_suffix(): void
    {
        $tester = new CommandTester(new MakeDomainEventCommand($this->engine));
        $tester->execute(['name' => 'UserRegistered', '--context' => 'User', '--aggregate' => 'User']);

        $this->assertFileExists($this->src('User/Domain/User/Event/UserRegistered.php'));
        $this->assertFileDoesNotExist($this->src('User/Domain/User/Event/UserRegisteredEvent.php'));
    }

    public function test_make_domain_event_namespace_and_class_name_are_correct(): void
    {
        $tester = new CommandTester(new MakeDomainEventCommand($this->engine));
        $tester->execute(['name' => 'UserRegistered', '--context' => 'User', '--aggregate' => 'User']);

        $content = file_get_contents($this->src('User/Domain/User/Event/UserRegistered.php'));
        $this->assertStringContainsString('namespace App\User\Domain\User\Event;', $content);
        $this->assertStringContainsString('final readonly class UserRegistered', $content);
    }

    public function test_make_domain_event_requires_aggregate(): void
    {
        $tester = new CommandTester(new MakeDomainEventCommand($this->engine));
        $tester->execute(['name' => 'UserRegistered', '--context' => 'User']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('--aggregate', $tester->getDisplay());
    }

    // ── make:domain-error ─────────────────────────────────────────────────────

    public function test_make_domain_error_generates_inside_aggregate_error_folder(): void
    {
        $tester = new CommandTester(new MakeDomainErrorCommand($this->engine));
        $tester->execute(['name' => 'UserNotFound', '--context' => 'User', '--aggregate' => 'User', '--status' => '404']);

        $this->assertFileExists($this->src('User/Domain/User/Error/UserNotFoundError.php'));
        $content = file_get_contents($this->src('User/Domain/User/Error/UserNotFoundError.php'));
        $this->assertStringContainsString('namespace App\User\Domain\User\Error;', $content);
        $this->assertStringContainsString('#[HttpStatus(404)]', $content);
    }

    public function test_make_domain_error_shared_generates_in_shared_error_folder(): void
    {
        $tester = new CommandTester(new MakeDomainErrorCommand($this->engine));
        $tester->execute(['name' => 'InvalidAgeGroup', '--context' => 'Registration', '--shared' => true]);

        $this->assertFileExists($this->src('Registration/Domain/Shared/Error/InvalidAgeGroupError.php'));
        $content = file_get_contents($this->src('Registration/Domain/Shared/Error/InvalidAgeGroupError.php'));
        $this->assertStringContainsString('namespace App\Registration\Domain\Shared\Error;', $content);
    }

    public function test_make_domain_error_fails_without_aggregate_or_shared(): void
    {
        $tester = new CommandTester(new MakeDomainErrorCommand($this->engine));
        $tester->execute(['name' => 'UserNotFound', '--context' => 'User']);

        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('--aggregate', $output);
        $this->assertStringContainsString('--shared', $output);
    }

    public function test_make_domain_error_fails_with_both_aggregate_and_shared(): void
    {
        $tester = new CommandTester(new MakeDomainErrorCommand($this->engine));
        $tester->execute(['name' => 'UserNotFound', '--context' => 'User', '--aggregate' => 'User', '--shared' => true]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    // ── make:domain-service ───────────────────────────────────────────────────

    public function test_make_domain_service_generates_inside_aggregate_service_folder(): void
    {
        $tester = new CommandTester(new MakeDomainServiceCommand($this->engine));
        $tester->execute(['name' => 'PublishFormService', '--context' => 'Registration', '--aggregate' => 'Form']);

        $this->assertFileExists($this->src('Registration/Domain/Form/Service/PublishFormService.php'));
    }

    public function test_make_domain_service_generates_inside_shared_service_folder(): void
    {
        $tester = new CommandTester(new MakeDomainServiceCommand($this->engine));
        $tester->execute(['name' => 'AgeGroupCalculator', '--context' => 'Registration', '--shared' => true]);

        $this->assertFileExists($this->src('Registration/Domain/Shared/Service/AgeGroupCalculator.php'));
    }

    public function test_make_domain_service_namespace_is_correct_for_aggregate(): void
    {
        $tester = new CommandTester(new MakeDomainServiceCommand($this->engine));
        $tester->execute(['name' => 'PublishFormService', '--context' => 'Registration', '--aggregate' => 'Form']);

        $content = file_get_contents($this->src('Registration/Domain/Form/Service/PublishFormService.php'));
        $this->assertStringContainsString('namespace App\Registration\Domain\Form\Service;', $content);
    }

    public function test_make_domain_service_namespace_is_correct_for_shared(): void
    {
        $tester = new CommandTester(new MakeDomainServiceCommand($this->engine));
        $tester->execute(['name' => 'AgeGroupCalculator', '--context' => 'Registration', '--shared' => true]);

        $content = file_get_contents($this->src('Registration/Domain/Shared/Service/AgeGroupCalculator.php'));
        $this->assertStringContainsString('namespace App\Registration\Domain\Shared\Service;', $content);
    }

    public function test_make_domain_service_contains_as_domain_service_attribute(): void
    {
        $tester = new CommandTester(new MakeDomainServiceCommand($this->engine));
        $tester->execute(['name' => 'AgeGroupCalculator', '--context' => 'Registration', '--shared' => true]);

        $content = file_get_contents($this->src('Registration/Domain/Shared/Service/AgeGroupCalculator.php'));
        $this->assertStringContainsString('#[AsDomainService]', $content);
        $this->assertStringContainsString('use Vortos\Domain\Attribute\AsDomainService;', $content);
    }

    public function test_make_domain_service_fails_without_aggregate_or_shared(): void
    {
        $tester = new CommandTester(new MakeDomainServiceCommand($this->engine));
        $tester->execute(['name' => 'AgeGroupCalculator', '--context' => 'Registration']);

        $this->assertSame(1, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('--aggregate', $output);
        $this->assertStringContainsString('--shared', $output);
    }

    public function test_make_domain_service_fails_with_both_aggregate_and_shared(): void
    {
        $tester = new CommandTester(new MakeDomainServiceCommand($this->engine));
        $tester->execute(['name' => 'AgeGroupCalculator', '--context' => 'Registration', '--aggregate' => 'Form', '--shared' => true]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    // ── make:hook ─────────────────────────────────────────────────────────────

    public function test_make_hook_before_handler_generates_correct_stub(): void
    {
        $tester = new CommandTester(new MakeHookCommand($this->engine));
        $tester->execute(['name' => 'TraceHandler', '--context' => 'User', '--type' => 'before-handler']);

        $this->assertFileExists($this->src('User/Infrastructure/Messaging/TraceHandlerHook.php'));
        $content = file_get_contents($this->src('User/Infrastructure/Messaging/TraceHandlerHook.php'));
        $this->assertStringContainsString('BeforeHandler', $content);
        $this->assertStringContainsString('string $consumerName', $content);
        $this->assertStringContainsString('string $handlerId', $content);
    }

    public function test_make_hook_after_handler_generates_correct_stub(): void
    {
        $tester = new CommandTester(new MakeHookCommand($this->engine));
        $tester->execute(['name' => 'AlertOnFailure', '--context' => 'User', '--type' => 'after-handler']);

        $this->assertFileExists($this->src('User/Infrastructure/Messaging/AlertOnFailureHook.php'));
        $content = file_get_contents($this->src('User/Infrastructure/Messaging/AlertOnFailureHook.php'));
        $this->assertStringContainsString('AfterHandler', $content);
        $this->assertStringContainsString('HandlerOutcome', $content);
        $this->assertStringContainsString('$latencyMs', $content);
        $this->assertStringContainsString('$throwable', $content);
    }

    public function test_make_hook_invalid_type_returns_failure(): void
    {
        $tester = new CommandTester(new MakeHookCommand($this->engine));
        $tester->execute(['name' => 'Foo', '--context' => 'User', '--type' => 'not-a-real-type']);

        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_make_hook_before_dispatch_still_works(): void
    {
        $tester = new CommandTester(new MakeHookCommand($this->engine));
        $tester->execute(['name' => 'AuditDispatch', '--context' => 'User', '--type' => 'before-dispatch']);

        $this->assertFileExists($this->src('User/Infrastructure/Messaging/AuditDispatchHook.php'));
        $content = file_get_contents($this->src('User/Infrastructure/Messaging/AuditDispatchHook.php'));
        $this->assertStringContainsString('BeforeDispatch', $content);
    }

    // ── make:context ──────────────────────────────────────────────────────────

    public function test_make_context_creates_singular_value_object_not_plural(): void
    {
        $tester = new CommandTester(new MakeContextCommand($this->engine));
        $tester->execute(['name' => 'Billing']);

        $this->assertDirectoryExists($this->src('Billing/Domain/Shared/ValueObject'));
        $this->assertDirectoryDoesNotExist($this->src('Billing/Domain/Shared/ValueObjects'));
    }

    public function test_make_context_creates_shared_error_and_service_folders(): void
    {
        $tester = new CommandTester(new MakeContextCommand($this->engine));
        $tester->execute(['name' => 'Billing']);

        $this->assertDirectoryExists($this->src('Billing/Domain/Shared/Error'));
        $this->assertDirectoryExists($this->src('Billing/Domain/Shared/Service'));
    }

    public function test_make_context_does_not_create_flat_type_domain_dirs(): void
    {
        $tester = new CommandTester(new MakeContextCommand($this->engine));
        $tester->execute(['name' => 'Billing']);

        $this->assertDirectoryDoesNotExist($this->src('Billing/Domain/Entity'));
        $this->assertDirectoryDoesNotExist($this->src('Billing/Domain/Event'));
        $this->assertDirectoryDoesNotExist($this->src('Billing/Domain/Error'));
        $this->assertDirectoryDoesNotExist($this->src('Billing/Domain/Repository'));
        $this->assertDirectoryDoesNotExist($this->src('Billing/Domain/ValueObject'));
    }

    public function test_make_context_creates_all_required_directories(): void
    {
        $tester = new CommandTester(new MakeContextCommand($this->engine));
        $tester->execute(['name' => 'Billing']);

        $expected = [
            'Billing/Domain/Shared/ValueObject',
            'Billing/Domain/Shared/Error',
            'Billing/Domain/Shared/Service',
            'Billing/Application/Command',
            'Billing/Application/Query',
            'Billing/Application/EventHandler',
            'Billing/Application/Projection',
            'Billing/Application/Policy',
            'Billing/Application/ReadModel',
            'Billing/Infrastructure/Repository',
            'Billing/Infrastructure/Messaging',
            'Billing/Infrastructure/Quota',
            'Billing/Infrastructure/Persistence/Mongo',
            'Billing/Representation/Controller',
            'Billing/Representation/Request',
        ];

        foreach ($expected as $dir) {
            $this->assertDirectoryExists($this->src($dir), "Missing directory: src/{$dir}");
        }
    }

    public function test_make_context_next_hint_suggests_make_aggregate(): void
    {
        $tester = new CommandTester(new MakeContextCommand($this->engine));
        $tester->execute(['name' => 'Billing']);

        $this->assertStringContainsString('make:aggregate', $tester->getDisplay());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function src(string $path): string
    {
        return $this->projectDir . '/src/' . $path;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
