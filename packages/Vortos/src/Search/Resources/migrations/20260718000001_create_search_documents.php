<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

/**
 * The unified, tenant-scoped search projection. Portable across DBAL engines: only base text
 * columns live here. The Postgres-only extras (generated `search_vector` tsvector column, GIN
 * indexes, trigram, row-level security) are installed out-of-band by `vortos:search:pg:install`,
 * because the portable Schema-diff seam can't express generated/expression/opclass indexes.
 */
return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Search';
    }

    public function id(): string
    {
        return 'search.create_search_documents';
    }

    public function description(): string
    {
        return 'Create the unified tenant-scoped search_documents projection.';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('search_documents'));

        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);            // UuidV4

        // Scoping
        $table->addColumn('tenant_id', 'string', ['length' => 255, 'notnull' => true]);     // org boundary (RLS)
        $table->addColumn('permission', 'string', ['length' => 128, 'notnull' => false]);   // gate for org-shared rows
        $table->addColumn('owner_member_id', 'string', ['length' => 255, 'notnull' => false]); // set = personal row

        // Identity
        $table->addColumn('doc_type', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('entity_id', 'string', ['length' => 255, 'notnull' => true]);

        // Searchable text (weights applied by the Postgres vector: title A, subtitle/keywords B, body C)
        $table->addColumn('title', 'text', ['notnull' => true]);
        $table->addColumn('subtitle', 'text', ['notnull' => true, 'default' => '']);
        $table->addColumn('body', 'text', ['notnull' => true, 'default' => '']);
        $table->addColumn('keywords', 'text', ['notnull' => true, 'default' => '']);

        // Presentation
        $table->addColumn('deeplink', 'string', ['length' => 1024, 'notnull' => true, 'default' => '']);
        $table->addColumn('meta', 'text', ['notnull' => true, 'default' => '{}']);           // JSON payload
        $table->addColumn('updated_at', 'string', ['length' => 32, 'notnull' => true]);      // Y-m-d H:i:s.u UTC

        $table->setPrimaryKey(['id']);

        // Natural key: one row per (tenant, type, entity) — the upsert + delete anchor.
        $table->addUniqueIndex(['tenant_id', 'doc_type', 'entity_id'], 'uq_search_entity');

        // Access paths
        $table->addIndex(['tenant_id', 'doc_type'], 'idx_search_tenant_type');   // type-filtered scans
        $table->addIndex(['tenant_id', 'updated_at'], 'idx_search_tenant_recent'); // recency order + portable-driver fallback
        $table->addIndex(['owner_member_id'], 'idx_search_owner');               // personal-row lookups
    }
};
