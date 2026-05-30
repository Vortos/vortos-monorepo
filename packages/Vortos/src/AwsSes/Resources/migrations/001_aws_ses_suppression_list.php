<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Ses';
    }

    public function id(): string
    {
        return 'ses.suppression_list';
    }

    public function description(): string
    {
        return 'SES suppression list — local mirror of the AWS account-level suppression list';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('aws_ses_suppression_list'));

        $table->addColumn('id',            'guid',               ['notnull' => true]);
        $table->addColumn('email_address', 'string',             ['length' => 320, 'notnull' => true]);
        $table->addColumn('reason',        'string',             ['length' => 20,  'notnull' => true]);
        $table->addColumn('suppressed_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('created_at',    'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['id']);

        // Uniqueness: one record per address, case-insensitive via normalised storage
        $table->addUniqueIndex(['email_address'], 'uq_ses_suppression_email');

        // Quick lookup for suppression check on every send
        $table->addIndex(['email_address'], 'idx_ses_suppression_email');
    }
};
