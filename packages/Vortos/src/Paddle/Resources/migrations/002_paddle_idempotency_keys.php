<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Paddle';
    }

    public function id(): string
    {
        return 'paddle.idempotency_keys';
    }

    public function description(): string
    {
        return 'Paddle idempotency keys — prevents duplicate outbound Paddle API calls';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('paddle_idempotency_keys'));

        $table->addColumn('key_id',     'string', ['length' => 36,  'notnull' => true]);
        $table->addColumn('operation',  'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('expires_at', 'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['key_id']);

        $table->addIndex(['expires_at'], 'idx_paddle_idempotency_keys_expires');
    }
};
