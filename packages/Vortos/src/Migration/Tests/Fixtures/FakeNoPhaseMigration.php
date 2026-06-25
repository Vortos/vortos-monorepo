<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Fixtures;

final class FakeNoPhaseMigration
{
    public function up(): void
    {
        // $this->addSql('CREATE TABLE new_table (id SERIAL PRIMARY KEY)');
    }
}
