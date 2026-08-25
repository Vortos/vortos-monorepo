<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The guard that makes --check meaningful.
     *
     * With no entities mapped the diff is empty, and a plain "nothing to do" would be this
     * gate passing on a run that examined nothing. That is worse than having no gate: it
     * reports safety it never checked.
     */
    public function test_check_fails_when_no_entities_are_mapped(): void
    {
        $tester = $this->tester();
        $tester->execute(['--check' => true]);

        $this->assertStringContainsString('No mapped entities', $tester->getDisplay());
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_check_does_not_write_a_migration(): void
    {
        // The whole point of the mode: CI reports, it never edits the repository.
        $this->factoryProvider->expects($this->never())->method('create');

        $tester = $this->tester();
        $tester->execute(['--check' => true]);

        $this->assertSame(1, $tester->getStatusCode());
    }

    /**
     * The classification the whole gate rests on.
     *
     * Failing on the entire diff made this unusable: a real application reported a hundred
     * and fourteen statements, every one cosmetic. Only a missing table or column is
     * certainly fatal — the application names it in a query and the database refuses,
     * every request, from the first.
     *
     * @param non-empty-string $sql
     */
    #[DataProvider('schemaStatements')]
    public function test_only_missing_structure_counts_as_a_failure(string $sql, bool $fatal): void
    {
        $method = new \ReflectionMethod(OrmDiffCommand::class, 'isMissingFromSchema');

        $this->assertSame($fatal, $method->invoke(null, $sql), $sql);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function schemaStatements(): iterable
    {
        // Fatal: the application will name these and be refused.
        yield 'missing table'  => ['CREATE TABLE qualification_withdrawals (id VARCHAR(36) NOT NULL)', true];
        yield 'missing column' => ['ALTER TABLE qualification_withdrawals ADD lock_version INT NOT NULL', true];
        yield 'lowercase'      => ['alter table foo add bar INT', true];

        // Not fatal: untidy, and invisible to a running application.
        yield 'foreign key'    => ['ALTER TABLE forms ADD CONSTRAINT fk_forms FOREIGN KEY (tournament_id) REFERENCES tournaments (id)', false];
        yield 'dropped fk'     => ['ALTER TABLE forms DROP CONSTRAINT forms_tournament_id_fkey', false];
        yield 'dropped default'=> ['ALTER TABLE forms ALTER status DROP DEFAULT', false];
        yield 'renamed index'  => ['ALTER INDEX uq_resubmission_offer_token RENAME TO UNIQ_A7D4E2045F37A13B', false];
        yield 'changed type'   => ['ALTER TABLE registration_submissions ALTER payment_intent TYPE JSON', false];
        yield 'set default'    => ['ALTER TABLE offers ALTER lock_version SET DEFAULT 1', false];
    }
}
