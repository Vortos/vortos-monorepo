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
        return 'paddle.subscriptions';
    }

    public function description(): string
    {
        return 'Paddle subscriptions — local mirror of Paddle subscription and subscription item state';
    }

    public function define(Schema $schema): void
    {
        $subs = $schema->createTable($this->t('paddle_subscriptions'));

        $subs->addColumn('id',             'string', ['length' => 50, 'notnull' => true]);
        $subs->addColumn('customer_id',    'string', ['length' => 50, 'notnull' => true]);
        $subs->addColumn('status',         'string', ['length' => 20, 'notnull' => true]);
        $subs->addColumn('currency_code',  'string', ['length' => 3,  'notnull' => true, 'fixed' => true]);
        $subs->addColumn('next_billed_at', 'datetime_immutable', ['notnull' => false]);
        $subs->addColumn('paused_at',      'datetime_immutable', ['notnull' => false]);
        $subs->addColumn('canceled_at',    'datetime_immutable', ['notnull' => false]);
        $subs->addColumn('created_at',     'datetime_immutable', ['notnull' => true]);
        $subs->addColumn('updated_at',     'datetime_immutable', ['notnull' => true]);

        $subs->setPrimaryKey(['id']);
        $subs->addIndex(['customer_id'], 'idx_paddle_subscriptions_customer');
        $subs->addIndex(['status'],      'idx_paddle_subscriptions_status');

        $items = $schema->createTable($this->t('paddle_subscription_items'));

        $items->addColumn('subscription_id', 'string',  ['length' => 50, 'notnull' => true]);
        $items->addColumn('price_id',        'string',  ['length' => 50, 'notnull' => true]);
        $items->addColumn('quantity',        'integer', ['notnull' => true, 'default' => 1]);
        $items->addColumn('status',          'string',  ['length' => 20, 'notnull' => true]);
        $items->addColumn('created_at',      'datetime_immutable', ['notnull' => true]);
        $items->addColumn('updated_at',      'datetime_immutable', ['notnull' => true]);

        $items->setPrimaryKey(['subscription_id', 'price_id']);
        $items->addIndex(['price_id'], 'idx_paddle_subscription_items_price');
    }
};
