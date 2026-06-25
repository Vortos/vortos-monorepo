<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Exporter\Database\DatabaseExporter;

final class DatabaseExportTest extends TestCase
{
    public function test_rds_instance(): void
    {
        $doc = (new DatabaseExporter())->export([
            'spec' => ['provider' => 'aws-rds', 'label' => 'main_db', 'engine' => 'postgres', 'engine_version' => '16', 'instance_class' => 'db.t3.micro'],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(includeVariables: false), true);
        $this->assertArrayHasKey('aws_db_instance', $decoded['resource']);
        $this->assertSame('postgres', $decoded['resource']['aws_db_instance']['main_db']['engine']);
    }

    public function test_cloudsql_instance(): void
    {
        $doc = (new DatabaseExporter())->export([
            'spec' => ['provider' => 'gcp-cloudsql', 'label' => 'main_db', 'engine' => 'postgres', 'engine_version' => '16'],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(includeVariables: false), true);
        $this->assertArrayHasKey('google_sql_database_instance', $decoded['resource']);
    }
}
