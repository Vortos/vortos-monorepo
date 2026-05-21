<?php

declare(strict_types=1);

namespace Vortos\Tests\PersistenceMongo;

use MongoDB\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\PersistenceMongo\DependencyInjection\Compiler\MongoReadRepositoryAutowirePass;
use Vortos\PersistenceMongo\Read\MongoReadRepository;
use Vortos\PersistenceMongo\Schema\Attribute\MongoCollection;

// --- fixture ---

#[MongoCollection('fakes')]
final class FakeReadRepository extends MongoReadRepository
{
    protected function collectionName(): string { return 'fakes'; }
    protected function fromDocument(array $doc): mixed { return $doc; }
    protected function indexes(): array { return []; }
}

// --- tests ---

final class MongoReadRepositoryAutowirePassTest extends TestCase
{
    private function container(bool $hasClient = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('vortos.persistence.mongo.database_name', 'testdb');

        if ($hasClient) {
            $container->setDefinition(Client::class, new Definition(Client::class));
        }

        return $container;
    }

    public function test_injects_client_and_database_name_into_subclass(): void
    {
        $container = $this->container();
        $container->setDefinition(FakeReadRepository::class, new Definition(FakeReadRepository::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $def = $container->getDefinition(FakeReadRepository::class);
        $this->assertInstanceOf(\Symfony\Component\DependencyInjection\Reference::class, $def->getArgument('$client'));
        $this->assertSame('%vortos.persistence.mongo.database_name%', $def->getArgument('$databaseName'));
    }

    public function test_adds_read_repository_tag_to_subclass(): void
    {
        $container = $this->container();
        $container->setDefinition(FakeReadRepository::class, new Definition(FakeReadRepository::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $tags = $container->getDefinition(FakeReadRepository::class)->getTags();
        $this->assertArrayHasKey('vortos.read_repository', $tags);
    }

    public function test_does_not_modify_non_read_repository_services(): void
    {
        $container = $this->container();
        $container->setDefinition('some.service', new Definition(\stdClass::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        $tags = $container->getDefinition('some.service')->getTags();
        $this->assertArrayNotHasKey('vortos.read_repository', $tags);
    }

    public function test_does_not_override_explicitly_set_args(): void
    {
        $container = $this->container();

        $def = new Definition(FakeReadRepository::class);
        $def->setArgument('$client', new \Symfony\Component\DependencyInjection\Reference('my.custom.client'));
        $def->setArgument('$databaseName', 'explicit_db');
        $container->setDefinition(FakeReadRepository::class, $def);

        (new MongoReadRepositoryAutowirePass())->process($container);

        $updatedDef = $container->getDefinition(FakeReadRepository::class);
        $this->assertSame('my.custom.client', (string) $updatedDef->getArgument('$client'));
        $this->assertSame('explicit_db', $updatedDef->getArgument('$databaseName'));
    }

    public function test_does_nothing_when_mongo_client_not_registered(): void
    {
        $container = $this->container(hasClient: false);
        $container->setDefinition(FakeReadRepository::class, new Definition(FakeReadRepository::class));

        (new MongoReadRepositoryAutowirePass())->process($container);

        // Pass should be a no-op — no args injected
        $def = $container->getDefinition(FakeReadRepository::class);
        $this->assertEmpty($def->getArguments());
    }

    public function test_does_not_duplicate_read_repository_tag(): void
    {
        $container = $this->container();

        $def = new Definition(FakeReadRepository::class);
        $def->addTag('vortos.read_repository');
        $container->setDefinition(FakeReadRepository::class, $def);

        (new MongoReadRepositoryAutowirePass())->process($container);

        $tags = $container->getDefinition(FakeReadRepository::class)->getTags();
        $this->assertCount(1, $tags['vortos.read_repository']);
    }
}
