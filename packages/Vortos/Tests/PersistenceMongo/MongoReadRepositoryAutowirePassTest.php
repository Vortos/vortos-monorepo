<?php

declare(strict_types=1);

namespace Vortos\Tests\PersistenceMongo;

use MongoDB\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Metrics\Attribute\DisableMetrics;
use Vortos\PersistenceMongo\DependencyInjection\Compiler\MongoReadRepositoryAutowirePass;
use Vortos\PersistenceMongo\Read\MongoStore;
use Vortos\PersistenceMongo\Schema\Attribute\MongoCollection;
use Vortos\Tracing\Attribute\DisableTracing;

// --- fixtures ---

#[MongoCollection('fakes')]
final class FakeReadRepository
{
    public function __construct(private readonly MongoStore $store) {}
}

#[MongoCollection('traced_off')]
#[DisableTracing]
final class FakeReadRepositoryNoTracing
{
    public function __construct(private readonly MongoStore $store) {}
}

#[MongoCollection('metrics_off')]
#[DisableMetrics]
final class FakeReadRepositoryNoMetrics
{
    public function __construct(private readonly MongoStore $store) {}
}

// --- tests ---

final class MongoReadRepositoryAutowirePassTest extends TestCase
{
    private function container(bool $hasClient = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.persistence.mongo.database_name', 'testdb');
        $container->setParameter('vortos.persistence.slow_query_threshold_ms', 100);

        if ($hasClient) {
            $container->setDefinition(Client::class, new Definition(Client::class));
        }

        return $container;
    }

    public function test_creates_mongo_store_service_for_annotated_repository(): void
    {
        $container = $this->container();
        $container->setDefinition(FakeReadRepository::class, new Definition(FakeReadRepository::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $storeId = 'vortos.mongo_store.' . FakeReadRepository::class;
        $this->assertTrue($container->hasDefinition($storeId));
        $this->assertSame(MongoStore::class, $container->getDefinition($storeId)->getClass());
    }

    public function test_injects_store_as_store_argument_of_repository(): void
    {
        $container = $this->container();
        $container->setDefinition(FakeReadRepository::class, new Definition(FakeReadRepository::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $repoDef  = $container->getDefinition(FakeReadRepository::class);
        $storeArg = $repoDef->getArgument('$store');

        $this->assertInstanceOf(Reference::class, $storeArg);
        $this->assertSame('vortos.mongo_store.' . FakeReadRepository::class, (string) $storeArg);
    }

    public function test_store_is_wired_with_client_and_database(): void
    {
        $container = $this->container();
        $container->setDefinition(FakeReadRepository::class, new Definition(FakeReadRepository::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $storeDef = $container->getDefinition('vortos.mongo_store.' . FakeReadRepository::class);
        $this->assertSame(Client::class, (string) $storeDef->getArgument('$client'));
        $this->assertSame('%vortos.persistence.mongo.database_name%', $storeDef->getArgument('$databaseName'));
        $this->assertSame('fakes', $storeDef->getArgument('$collectionName'));
    }

    public function test_store_gets_read_repository_tag_by_default(): void
    {
        $container = $this->container();
        $container->setDefinition(FakeReadRepository::class, new Definition(FakeReadRepository::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $storeDef = $container->getDefinition('vortos.mongo_store.' . FakeReadRepository::class);
        $this->assertArrayHasKey('vortos.read_repository', $storeDef->getTags());
    }

    public function test_disable_tracing_skips_read_repository_tag_on_store(): void
    {
        $container = $this->container();
        $container->setDefinition(FakeReadRepositoryNoTracing::class, new Definition(FakeReadRepositoryNoTracing::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $storeDef = $container->getDefinition('vortos.mongo_store.' . FakeReadRepositoryNoTracing::class);
        $this->assertArrayNotHasKey('vortos.read_repository', $storeDef->getTags());
    }

    public function test_disable_metrics_adds_skip_metrics_tag_on_store(): void
    {
        $container = $this->container();
        $container->setDefinition(FakeReadRepositoryNoMetrics::class, new Definition(FakeReadRepositoryNoMetrics::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $storeDef = $container->getDefinition('vortos.mongo_store.' . FakeReadRepositoryNoMetrics::class);
        $this->assertArrayHasKey('vortos.skip_metrics', $storeDef->getTags());
    }

    public function test_does_not_modify_non_annotated_services(): void
    {
        $container = $this->container();
        $container->setDefinition('some.service', new Definition(\stdClass::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $def = $container->getDefinition('some.service');
        $this->assertEmpty($def->getArguments());
        $this->assertArrayNotHasKey('vortos.read_repository', $def->getTags());
    }

    public function test_does_nothing_when_mongo_client_not_registered(): void
    {
        $container = $this->container(hasClient: false);
        $container->setDefinition(FakeReadRepository::class, new Definition(FakeReadRepository::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $this->assertEmpty($container->getDefinition(FakeReadRepository::class)->getArguments());
        $this->assertFalse($container->hasDefinition('vortos.mongo_store.' . FakeReadRepository::class));
    }
}
