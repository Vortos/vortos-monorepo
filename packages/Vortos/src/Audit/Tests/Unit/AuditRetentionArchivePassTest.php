<?php

declare(strict_types=1);

namespace Vortos\Audit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Audit\Console\AuditRetentionCommand;
use Vortos\Audit\DependencyInjection\Compiler\AuditRetentionArchivePass;
use Vortos\Audit\Doctor\AuditDoctor;
use Vortos\Audit\Retention\AuditArchiveWriterInterface;
use Vortos\Audit\Retention\AuditRetentionPolicy;
use Vortos\Audit\Retention\AuditRetentionSweeper;
use Vortos\Audit\Retention\ObjectStore\ObjectStoreArchiveWriter;
use Vortos\Audit\Storage\Dbal\DbalAuditStore;

final class AuditRetentionArchivePassTest extends TestCase
{
    private function baseContainer(): ContainerBuilder
    {
        $c = new ContainerBuilder();
        // Minimal fixtures the pass looks for (definitions only — never instantiated).
        $c->setDefinition(DbalAuditStore::class, new Definition(DbalAuditStore::class));
        $c->setDefinition(AuditRetentionPolicy::class, new Definition(AuditRetentionPolicy::class));
        $c->setDefinition(AuditRetentionCommand::class, (new Definition(AuditRetentionCommand::class))->setArgument('$sweeper', null));
        $c->setDefinition(AuditDoctor::class, (new Definition(AuditDoctor::class))->setArgument('$facts', ['has_archive_target' => false]));
        $c->setParameter('vortos_audit.archive_key_prefix', 'audit-archive');
        $c->setParameter('vortos_audit.retention_batch_size', 500);

        return $c;
    }

    public function test_wires_the_archive_target_when_the_immediate_object_store_is_available(): void
    {
        $c = $this->baseContainer();
        // Stand in for the object-store immediate alias.
        $c->setDefinition('some.immediate.impl', new Definition(\stdClass::class));
        $c->setAlias('Vortos\ObjectStore\Contract\ImmediateObjectStoreInterface', 'some.immediate.impl');

        (new AuditRetentionArchivePass())->process($c);

        self::assertTrue($c->hasDefinition(ObjectStoreArchiveWriter::class));
        self::assertTrue($c->hasDefinition(AuditRetentionSweeper::class));
        self::assertTrue($c->hasAlias(AuditArchiveWriterInterface::class));
        self::assertInstanceOf(Reference::class, $c->getDefinition(AuditRetentionCommand::class)->getArgument('$sweeper'));
        self::assertTrue($c->getDefinition(AuditDoctor::class)->getArgument('$facts')['has_archive_target']);
    }

    public function test_does_nothing_without_an_archive_target(): void
    {
        $c = $this->baseContainer(); // no ImmediateObjectStoreInterface alias

        (new AuditRetentionArchivePass())->process($c);

        self::assertFalse($c->hasDefinition(AuditRetentionSweeper::class));
        self::assertFalse($c->hasAlias(AuditArchiveWriterInterface::class));
        self::assertNull($c->getDefinition(AuditRetentionCommand::class)->getArgument('$sweeper'));
        self::assertFalse($c->getDefinition(AuditDoctor::class)->getArgument('$facts')['has_archive_target']);
    }
}
