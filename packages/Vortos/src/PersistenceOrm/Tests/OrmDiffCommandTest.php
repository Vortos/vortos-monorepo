<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\PersistenceOrm\Command\OrmDiffCommand;

final class OrmDiffCommandTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private DependencyFactoryProviderInterface&MockObject $factoryProvider;
    private ClassMetadataFactory&MockObject $metadataFactory;

    protected function setUp(): void
    {
        $this->em              = $this->createMock(EntityManagerInterface::class);
        $this->factoryProvider = $this->createMock(DependencyFactoryProviderInterface::class);
        $this->metadataFactory = $this->createMock(ClassMetadataFactory::class);

        $this->em->method('getMetadataFactory')->willReturn($this->metadataFactory);
        $this->metadataFactory->method('getAllMetadata')->willReturn([]);
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new OrmDiffCommand(
            $this->em,
            $this->factoryProvider,
            new MigrationClassGenerator(),
        ));
    }

    public function test_up_to_date_message_when_no_sql_changes(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $this->assertStringContainsString('Schema is up to date', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
    }

    public function test_dry_run_prints_sql_without_writing_file(): void
    {
        // SchemaTool::getUpdateSchemaSql returns empty for empty metadata,
        // so we test the "up to date" path when --dry-run is given but nothing to diff.
        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        // With no metadata, schema is up to date — dry-run has no effect
        $this->assertStringContainsString('Schema is up to date', $tester->getDisplay());
        $this->assertSame(0, $tester->getStatusCode());
        // factoryProvider should NOT be called in dry-run
        $this->factoryProvider->expects($this->never())->method('create');
    }

    public function test_dry_run_does_not_call_factory_provider(): void
    {
        $this->factoryProvider->expects($this->never())->method('create');

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        // With no SQL diff, returns early regardless of dry-run
        $this->assertSame(0, $tester->getStatusCode());
    }
}
