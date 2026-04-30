<?php

declare(strict_types=1);

namespace Vortos\Migration\Generator;

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
    public function generateFromSql(
        string $className,
        string $namespace,
        string $description,
        string $upSql,
    ): string {
        $addSqlCalls      = $this->buildAddSqlCalls($upSql);
        $escapedDesc      = $this->escapeString($description);

        return $this->renderTemplate($className, $namespace, $escapedDesc, $addSqlCalls, down: null);
    }

    public function generateEmpty(
        string $className,
        string $namespace,
        string $description,
    ): string {
        $escapedDesc = $this->escapeString($description);
        $body        = "        // Write your migration SQL here using \$this->addSql()\n";

        return $this->renderTemplate($className, $namespace, $escapedDesc, $body, down: 'manual');
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

        $lines = '';
        foreach (array_values($statements) as $statement) {
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
    ): string {
        if ($down === 'manual') {
            $downBody = "        // Reverse the up() migration using \$this->addSql()\n";
        } else {
            $downBody = "        \$this->throwIrreversibleMigrationException(\n" .
                        "            'This migration was generated from a module SQL stub and has no automatic rollback.'\n" .
                        "        );\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

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
