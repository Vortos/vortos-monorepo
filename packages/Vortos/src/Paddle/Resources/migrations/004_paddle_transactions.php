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
        return 'paddle.transactions';
    }

    public function description(): string
    {
        return 'Paddle transactions and adjustments — local mirror of Paddle billing transaction state';
    }

    public function define(Schema $schema): void
    {
        $txns = $schema->createTable('paddle_transactions');

        $txns->addColumn('id',              'string', ['length' => 50, 'notnull' => true]);
        $txns->addColumn('customer_id',     'string', ['length' => 50, 'notnull' => false]);
        $txns->addColumn('subscription_id', 'string', ['length' => 50, 'notnull' => false]);
        $txns->addColumn('status',          'string', ['length' => 20, 'notnull' => true]);
        $txns->addColumn('currency_code',   'string', ['length' => 3,  'notnull' => true, 'fixed' => true]);
        $txns->addColumn('total',           'string', ['length' => 20, 'notnull' => true]);
        $txns->addColumn('billed_at',       'datetime_immutable', ['notnull' => false]);
        $txns->addColumn('created_at',      'datetime_immutable', ['notnull' => true]);
        $txns->addColumn('updated_at',      'datetime_immutable', ['notnull' => true]);

        $txns->setPrimaryKey(['id']);
        $txns->addIndex(['customer_id'],     'idx_paddle_transactions_customer');
        $txns->addIndex(['subscription_id'], 'idx_paddle_transactions_subscription');
        $txns->addIndex(['status'],          'idx_paddle_transactions_status');

        $adjs = $schema->createTable('paddle_adjustments');

        $adjs->addColumn('id',             'string', ['length' => 50, 'notnull' => true]);
        $adjs->addColumn('transaction_id', 'string', ['length' => 50, 'notnull' => true]);
        $adjs->addColumn('customer_id',    'string', ['length' => 50, 'notnull' => true]);
        $adjs->addColumn('action',         'string', ['length' => 20, 'notnull' => true]);
        $adjs->addColumn('status',         'string', ['length' => 30, 'notnull' => true]);
        $adjs->addColumn('total',          'string', ['length' => 20, 'notnull' => true]);
        $adjs->addColumn('currency_code',  'string', ['length' => 3,  'notnull' => true, 'fixed' => true]);
        $adjs->addColumn('reason',         'text',   ['notnull' => true]);
        $adjs->addColumn('created_at',     'datetime_immutable', ['notnull' => true]);

        $adjs->setPrimaryKey(['id']);
        $adjs->addIndex(['transaction_id'], 'idx_paddle_adjustments_transaction');
        $adjs->addIndex(['customer_id'],    'idx_paddle_adjustments_customer');
    }
};
