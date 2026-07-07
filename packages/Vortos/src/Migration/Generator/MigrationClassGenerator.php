<?php

declare(strict_types=1);

namespace Vortos\Migration\Generator;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Vortos\Migration\Safety\DestructiveSqlDetector;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\ModuleSchemaProviderInterface;
use Vortos\Migration\Service\SchemaDiffStatementFactory;

/**
 * Generates Doctrine AbstractMigration PHP class source from SQL content.
 *
 * Used by vortos:migrate:publish to convert module SQL stubs into executable
 * Doctrine migration classes, and by vortos:migrate:make to scaffold empty migrations.
 *
 * SQL stubs often contain multiple statements separated by semicolons (e.g. CREATE TABLE
 * followed by CREATE INDEX). Each statement is emitted as a separate addSql() call
 * so Doctrine tracks them independently.
 *
 * Generated migrations from SQL stubs have a no-op down() that throws
 * IrreversibleMigrationException — stubs have no rollback SQL and no automatic
 * reversal can be safely inferred. Developers who need rollback should hand-write it.
 */
final class MigrationClassGenerator
{
    private readonly DestructiveSqlDetector $destructiveDetector;

    public function __construct(?DestructiveSqlDetector $destructiveDetector = null)
    {
        $this->destructiveDetector = $destructiveDetector ?? new DestructiveSqlDetector();
    }

    /**
     * Generate a migration for a single schema provider with no cumulative base. Alter-style
     * providers (which guard an ALTER on a table owned by another provider) need the cumulative
     * base to see that table — for those, the publisher precomputes statements via
     * SchemaDiffStatementFactory and calls generateFromStatements() instead. This single-provider
     * path is diff-based too (empty base → after), so a plain CREATE provider renders identically
     * to the old toSql() behaviour.
     */
    public function generateFromSchemaProvider(
        string $className,
        string $namespace,
        ModuleSchemaProviderInterface $provider,
        ?AbstractPlatform $platform = null,
    ): string {
        $factory    = new SchemaDiffStatementFactory($platform);
        $byRelative = $factory->statementsFor([['relative' => 'single', 'provider' => $provider]]);

        return $this->generateFromStatements(
            $className,
            $namespace,
            $provider->description(),
            $byRelative['single'],
        );
    }

    /**
     * Render a migration from a precomputed, idempotent list of SQL statements. Used by the
     * publisher with SchemaDiffStatementFactory output so alter-style providers emit correct
     * ALTER … ADD SQL against the cumulative schema.
     *
     * @param list<string> $statements
     */
    public function generateFromStatements(
        string $className,
        string $namespace,
        string $description,
        array $statements,
    ): string {
        return $this->renderTemplate(
            $className,
            $namespace,
            $this->escapeString($description),
            $this->buildAddSqlCallsFromStatements($statements),
            down: null,
            phase: $this->phaseForStatements($statements),
        );
    }

    public function generateFromSql(
        string $className,
        string $namespace,
        string $description,
        string $upSql,
    ): string {
        $addSqlCalls      = $this->buildAddSqlCalls($upSql);
        $escapedDesc      = $this->escapeString($description);

        return $this->renderTemplate(
            $className,
            $namespace,
            $escapedDesc,
            $addSqlCalls,
            down: null,
            phase: $this->destructiveDetector->isDestructive($upSql) ? MigrationPhase::Contract : MigrationPhase::Expand,
        );
    }

    public function generateEmpty(
        string $className,
        string $namespace,
        string $description,
    ): string {
        $escapedDesc = $this->escapeString($description);
        $body        = "        // Write your migration SQL here using \$this->addSql()\n";

        return $this->renderTemplate($className, $namespace, $escapedDesc, $body, down: 'manual', phase: MigrationPhase::Expand);
    }

    public function generateAggregate(
        string $className,
        string $namespace,
        string $tableName,
    ): string {
        $escapedTable = $this->escapeString($tableName);
        $escapedDesc  = $this->escapeString('Create ' . str_replace('_', ' ', $tableName) . ' table');

        $upBody = <<<PHP
        \$this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS {$escapedTable} (
                id           VARCHAR(36) NOT NULL,
                lock_version INTEGER     NOT NULL DEFAULT 0,

                PRIMARY KEY (id)
            )
        SQL);
PHP;
        $upBody .= "\n";

        $downBody = "        \$this->addSql('DROP TABLE IF EXISTS {$escapedTable}');\n";

        // CREATE TABLE IF NOT EXISTS is purely additive.
        return $this->renderTemplate($className, $namespace, $escapedDesc, $upBody, down: $downBody, phase: MigrationPhase::Expand);
    }

    /**
     * Classify a generated migration's deploy phase from its statements. Any destructive DDL ⇒
     * Contract (ship behind the soak/flag gate); otherwise Expand (additive, safe to apply eagerly).
     *
     * @param list<string> $statements
     */
    private function phaseForStatements(array $statements): MigrationPhase
    {
        return $this->destructiveDetector->anyDestructive($statements)
            ? MigrationPhase::Contract
            : MigrationPhase::Expand;
    }

    public function buildClassName(string $timestamp, int $sequence = 0): string
    {
        return $sequence > 0
            ? sprintf('Version%s%02d', $timestamp, $sequence)
            : 'Version' . $timestamp;
    }

    public function descriptionFromFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        // Strip leading numeric prefix: "001_vortos_outbox" → "vortos_outbox"
        $name = preg_replace('/^\d+_/', '', $name) ?? $name;
        // Underscores and hyphens → spaces, then title-case
        $name = str_replace(['_', '-'], ' ', $name);

        return ucfirst(strtolower($name));
    }

    private function buildAddSqlCalls(string $sql): string
    {
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            static fn(string $s) => $s !== '',
        );

        return $this->buildAddSqlCallsFromStatements(array_values($statements));
    }

    /**
     * @param string[] $statements
     */
    private function buildAddSqlCallsFromStatements(array $statements): string
    {
        $lines = '';
        foreach ($statements as $statement) {
            $escaped = $this->escapeString($statement);
            $lines  .= "        \$this->addSql('{$escaped}');\n";
        }

        return $lines;
    }

    private function renderTemplate(
        string $className,
        string $namespace,
        string $escapedDesc,
        string $upBody,
        ?string $down,
        MigrationPhase $phase = MigrationPhase::Expand,
    ): string {
        if ($down === 'manual') {
            $downBody = "        // Reverse the up() migration using \$this->addSql()\n";
        } elseif ($down !== null) {
            $downBody = $down;
        } else {
            $downBody = "        \$this->throwIrreversibleMigrationException(\n" .
                        "            'This migration was generated from a module SQL stub and has no automatic rollback.'\n" .
                        "        );\n";
        }

        $phaseCase = ucfirst($phase->value);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Vortos\Migration\Attribute\DeployPhase;
use Vortos\Migration\Schema\MigrationPhase;

#[DeployPhase(MigrationPhase::{$phaseCase})]
final class {$className} extends AbstractMigration
{
    public function getDescription(): string
    {
        return '{$escapedDesc}';
    }

    public function up(Schema \$schema): void
    {
{$upBody}    }

    public function down(Schema \$schema): void
    {
{$downBody}    }
}
PHP;
    }

    private function escapeString(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
