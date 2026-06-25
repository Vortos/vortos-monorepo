<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Exporter\Queue\QueueExporter;
use Vortos\Iac\Exporter\Queue\QueueProvider;

final class NoDirectProvisionArchTest extends TestCase
{
    public function test_no_rdkafka_admin_client_reference(): void
    {
        $iacRoot = dirname(__DIR__, 2);
        $violations = [];

        foreach ($this->phpFiles($iacRoot) as $file) {
            if (str_contains($file, '/Tests/')) {
                continue;
            }

            $contents = (string) file_get_contents($file);

            if (stripos($contents, 'AdminClient') !== false && stripos($contents, 'rdkafka') !== false) {
                $violations[] = $file . ': references rdkafka AdminClient';
            }
        }

        $this->assertSame([], $violations, 'No rdkafka AdminClient reference allowed (direct_provision=false).');
    }

    public function test_queue_exporter_emits_no_kafka_resources(): void
    {
        foreach (QueueProvider::cases() as $provider) {
            $entry = [
                'spec' => [
                    'provider' => $provider->value,
                    'label' => 'test_queue',
                    'queue_name' => 'my-queue',
                ],
                'allowed_literals' => [],
            ];

            $document = (new QueueExporter())->export($entry);
            $rendered = $document->render(includeVariables: false);

            $this->assertStringNotContainsString('kafka', strtolower($rendered),
                sprintf('Queue exporter (%s) must not emit Kafka resources.', $provider->value));
        }
    }

    /** @return iterable<string> */
    private function phpFiles(string $dir): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
