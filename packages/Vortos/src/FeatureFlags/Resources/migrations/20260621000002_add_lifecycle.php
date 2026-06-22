<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'FeatureFlags';
    }

    public function id(): string
    {
        return 'feature_flags.add_lifecycle';
    }

    public function description(): string
    {
        return 'Add lifecycle, owner, expires_at to feature flags (Block 12)';
    }

    public function define(Schema $schema): void
    {
        if (!$schema->hasTable($this->t('feature_flags'))) {
            return;
        }

        $flags = $schema->getTable($this->t('feature_flags'));

        if (!$flags->hasColumn('lifecycle')) {
            $flags->addColumn('lifecycle', 'string', [
                'length'  => 16,
                'notnull' => true,
                'default' => 'active',
            ]);
        }

        if (!$flags->hasColumn('owner')) {
            $flags->addColumn('owner', 'string', [
                'length'  => 191,
                'notnull' => false,
            ]);
        }

        if (!$flags->hasColumn('expires_at')) {
            $flags->addColumn('expires_at', 'datetime_immutable', [
                'notnull' => false,
            ]);
            $flags->addIndex(['expires_at'], 'idx_ff_expires_at');
        }

        // Index lifecycle for staleness queries.
        if (!$flags->hasIndex('idx_ff_lifecycle')) {
            $flags->addIndex(['lifecycle'], 'idx_ff_lifecycle');
        }
    }
};
