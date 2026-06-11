<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Iac\Command\IacExportCommand;
use Vortos\Iac\Export\ExportRunner;
use Vortos\Iac\Export\SafeFileWriter;
use Vortos\Iac\Exporter\Kafka\KafkaProvider;
use Vortos\Iac\Exporter\Kafka\KafkaTopicsExporter;
use Vortos\Iac\Exporter\Kafka\Mapper\ConfluentTopicMapper;
use Vortos\Iac\Exporter\Kafka\Mapper\MongeyKafkaTopicMapper;

final class IacExportCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos-iac-cmd-' . uniqid();
        mkdir($this->projectDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->projectDir);
        }
    }

    /** @return list<array<string, mixed>> */
    private function exports(?array $topics = null): array
    {
        return [[
            'name' => 'kafka-topics',
            'exporter' => KafkaTopicsExporter::class,
            'output_file' => 'infra/kafka_topics.tf.json',
            'variables_file' => 'infra/kafka_topics_variables.tf.json',
            'allowed_literals' => [],
            'spec' => [
                'provider' => KafkaProvider::Kafka->value,
                'cluster_ref' => null,
                'topics' => $topics ?? [[
                    'label' => 'orders_placed',
                    'name' => 'orders.placed',
                    'partitions' => 12,
                    'replication' => 3,
                    'config' => [],
                ]],
            ],
        ]];
    }

    private function tester(array $exports): CommandTester
    {
        $runner = new ExportRunner(
            [KafkaTopicsExporter::class => new KafkaTopicsExporter([new ConfluentTopicMapper(), new MongeyKafkaTopicMapper()])],
            $exports,
            new SafeFileWriter($this->projectDir),
        );

        return new CommandTester(new IacExportCommand($runner));
    }

    public function test_write_then_check_roundtrip(): void
    {
        $tester = $this->tester($this->exports());

        $this->assertSame(0, $tester->execute([]));
        $this->assertFileExists($this->projectDir . '/infra/kafka_topics.tf.json');
        $this->assertStringContainsString('written', $tester->getDisplay());

        $this->assertSame(0, $tester->execute(['--check' => true]));
        $this->assertStringContainsString('All generated files match', $tester->getDisplay());
    }

    public function test_check_fails_with_exit_1_on_drift(): void
    {
        $tester = $this->tester($this->exports());

        $this->assertSame(1, $tester->execute(['--check' => true]), 'Missing files are drift');

        $tester->execute([]);
        file_put_contents($this->projectDir . '/infra/kafka_topics.tf.json', "// tampered\n", FILE_APPEND);

        $this->assertSame(1, $tester->execute(['--check' => true]));
        $this->assertStringContainsString('drifted', $tester->getDisplay());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $tester = $this->tester($this->exports());

        $this->assertSame(0, $tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString('kafka_topic', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->projectDir . '/infra/kafka_topics.tf.json');
    }

    public function test_check_and_dry_run_are_mutually_exclusive(): void
    {
        $this->assertSame(2, $this->tester($this->exports())->execute(['--check' => true, '--dry-run' => true]));
    }

    public function test_zero_resources_warns(): void
    {
        $tester = $this->tester($this->exports(topics: []));
        $tester->execute([]);

        $this->assertStringContainsString('matched zero resources', $tester->getDisplay());
    }

    public function test_no_exporters_warns_but_succeeds(): void
    {
        $tester = $this->tester([]);

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('No exporters registered', $tester->getDisplay());
    }

    public function test_unknown_exporter_class_fails_with_exit_2(): void
    {
        $exports = $this->exports();
        $exports[0]['exporter'] = 'Vortos\\Missing\\Exporter';

        $this->assertSame(2, $this->tester($exports)->execute([]));
    }
}
