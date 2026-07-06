<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;
use Vortos\Migration\Schema\ModuleSchemaProviderInterface;
use Vortos\Migration\Service\SchemaDiffStatementFactory;

/**
 * R7-1 / PUBLISH-1 end-to-end guard against the REAL Backup schema providers: publishing the
 * encryption-alter provider against the cumulative base (which includes the catalog creator)
 * must yield an ALTER … ADD carrying all four backup_catalog encryption columns. This is the
 * exact production failure that broke every backup run.
 */
final class BackupCatalogAlterPublishesTest extends TestCase
{
    public function test_real_backup_encryption_provider_emits_four_catalog_columns(): void
    {
        AbstractModuleSchemaProvider::setPrefix('vortos_');

        $dir = \dirname(__DIR__, 3) . '/Backup/Resources/migrations';
        $ordered = [];
        foreach (glob($dir . '/*.php') ?: [] as $path) {
            /** @var mixed $provider */
            $provider = require $path;
            if ($provider instanceof ModuleSchemaProviderInterface) {
                $ordered[] = ['relative' => basename($path), 'provider' => $provider];
            }
        }

        // Sort by filename/timestamp exactly like the publisher, so the creator precedes the alter.
        usort($ordered, static fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

        $result = (new SchemaDiffStatementFactory())->statementsFor($ordered);

        $alterSql = '';
        foreach ($result as $statements) {
            $joined = implode("\n", $statements);
            if (stripos($joined, 'encryption_provider') !== false) {
                $alterSql = $joined;
                break;
            }
        }

        $this->assertNotSame('', $alterSql, 'A migration adding encryption_provider must be generated.');
        foreach (['encryption_provider', 'encryption_recipient', 'encryption_aead_id', 'secondary_store_key'] as $column) {
            $this->assertStringContainsStringIgnoringCase($column, $alterSql, "Missing catalog column: {$column}");
        }
        $this->assertStringContainsStringIgnoringCase('ALTER TABLE', $alterSql);
    }
}
