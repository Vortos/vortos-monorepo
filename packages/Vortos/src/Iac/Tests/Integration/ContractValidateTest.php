<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\BinaryResolver;
use Vortos\Iac\Driver\Terraform\SystemProcessRunner;
use Vortos\Iac\Exporter\Compute\ComputeExporter;
use Vortos\Iac\Lifecycle\StateBackend\StateBackendExporter;

final class ContractValidateTest extends TestCase
{
    private string $workDir;
    private SystemProcessRunner $runner;
    private string $binary;

    protected function setUp(): void
    {
        $this->runner = new SystemProcessRunner();
        $resolver = new BinaryResolver($this->runner);
        try {
            $this->binary = $resolver->resolve();
        } catch (\Throwable) {
            $this->markTestSkipped('tofu/terraform binary not available.');
        }

        $this->workDir = sys_get_temp_dir() . '/iac-validate-' . bin2hex(random_bytes(8));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            $this->rmrf($this->workDir);
        }
    }

    public function test_null_provider_exporter_validates(): void
    {
        $doc = (new ComputeExporter())->export([
            'spec' => ['provider' => 'generic-vps', 'label' => 'test'],
            'allowed_literals' => [],
        ]);

        file_put_contents($this->workDir . '/main.tf.json', $doc->render(includeVariables: false));

        $init = $this->runner->run(
            [$this->binary, 'init', '-input=false', '-no-color', '-backend=false'],
            $this->workDir,
            ['PATH' => '/usr/local/bin:/usr/bin:/bin'],
            60,
        );
        $this->assertTrue($init->isSuccess(), 'init failed: ' . $init->stderr);

        $validate = $this->runner->run(
            [$this->binary, 'validate', '-json', '-no-color'],
            $this->workDir,
            ['PATH' => '/usr/local/bin:/usr/bin:/bin'],
            30,
        );
        $this->assertTrue($validate->isSuccess(), 'validate failed: ' . $validate->stderr);
    }

    public function test_state_backend_local_inits(): void
    {
        $doc = (new StateBackendExporter())->export([
            'spec' => ['provider' => 'local', 'path' => 'terraform.tfstate'],
            'allowed_literals' => [],
        ]);

        file_put_contents($this->workDir . '/backend.tf.json', $doc->render());

        $init = $this->runner->run(
            [$this->binary, 'init', '-input=false', '-no-color'],
            $this->workDir,
            ['PATH' => '/usr/local/bin:/usr/bin:/bin'],
            60,
        );
        $this->assertTrue($init->isSuccess(), 'Local backend init failed: ' . $init->stderr);
    }

    /** @dataProvider cloudProviderExporterProvider */
    public function test_cloud_provider_exports_produce_valid_json_structure(string $exporterClass, array $entry): void
    {
        $exporter = new $exporterClass();
        $doc = $exporter->export($entry);
        $json = $doc->render(includeVariables: false);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'Exporter output must be valid JSON.');
        $this->assertArrayHasKey('//', $decoded, 'Must contain generated marker.');
        $this->assertTrue(
            isset($decoded['resource']) || isset($decoded['terraform']),
            'Must contain resource or terraform block.',
        );
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function cloudProviderExporterProvider(): iterable
    {
        yield 'network-aws' => [\Vortos\Iac\Exporter\Network\NetworkExporter::class, ['spec' => ['provider' => 'aws', 'label' => 'test', 'vpc_cidr' => '10.0.0.0/16'], 'allowed_literals' => []]];
        yield 'network-gcp' => [\Vortos\Iac\Exporter\Network\NetworkExporter::class, ['spec' => ['provider' => 'gcp', 'label' => 'test'], 'allowed_literals' => []]];
        yield 'database-rds' => [\Vortos\Iac\Exporter\Database\DatabaseExporter::class, ['spec' => ['provider' => 'aws-rds', 'label' => 'test', 'engine' => 'postgres'], 'allowed_literals' => []]];
        yield 'database-cloudsql' => [\Vortos\Iac\Exporter\Database\DatabaseExporter::class, ['spec' => ['provider' => 'gcp-cloudsql', 'label' => 'test'], 'allowed_literals' => []]];
        yield 'cache-elasticache' => [\Vortos\Iac\Exporter\Cache\CacheExporter::class, ['spec' => ['provider' => 'aws-elasticache', 'label' => 'test'], 'allowed_literals' => []]];
        yield 'cache-memorystore' => [\Vortos\Iac\Exporter\Cache\CacheExporter::class, ['spec' => ['provider' => 'gcp-memorystore', 'label' => 'test'], 'allowed_literals' => []]];
        yield 'dns-route53' => [\Vortos\Iac\Exporter\Dns\DnsExporter::class, ['spec' => ['provider' => 'aws-route53', 'label' => 'test', 'zone_name' => 'example.com', 'records' => [['name' => 'www', 'type' => 'A', 'value' => '1.2.3.4']]], 'allowed_literals' => []]];
        yield 'dns-cloudflare' => [\Vortos\Iac\Exporter\Dns\DnsExporter::class, ['spec' => ['provider' => 'cloudflare', 'label' => 'test', 'zone_name' => 'example.com', 'records' => []], 'allowed_literals' => []]];
        yield 'dns-gcp' => [\Vortos\Iac\Exporter\Dns\DnsExporter::class, ['spec' => ['provider' => 'gcp', 'label' => 'test', 'zone_name' => 'example.com', 'records' => []], 'allowed_literals' => []]];
        yield 'iam-aws' => [\Vortos\Iac\Exporter\Iam\IamExporter::class, ['spec' => ['provider' => 'aws', 'label' => 'test', 'role_name' => 'test-role', 'assume_role_policy' => '{}'], 'allowed_literals' => []]];
        yield 'iam-gcp' => [\Vortos\Iac\Exporter\Iam\IamExporter::class, ['spec' => ['provider' => 'gcp', 'label' => 'test', 'service_account_id' => 'test-sa', 'project' => 'my-project'], 'allowed_literals' => []]];
        yield 'queue-sqs' => [\Vortos\Iac\Exporter\Queue\QueueExporter::class, ['spec' => ['provider' => 'aws-sqs', 'label' => 'test', 'queue_name' => 'test-queue'], 'allowed_literals' => []]];
        yield 'queue-pubsub' => [\Vortos\Iac\Exporter\Queue\QueueExporter::class, ['spec' => ['provider' => 'gcp-pubsub', 'label' => 'test', 'queue_name' => 'test-topic'], 'allowed_literals' => []]];
        yield 'compute-aws' => [\Vortos\Iac\Exporter\Compute\ComputeExporter::class, ['spec' => ['provider' => 'aws', 'label' => 'test', 'ami' => 'ami-12345', 'instance_type' => 't3.micro'], 'allowed_literals' => []]];
        yield 'compute-gcp' => [\Vortos\Iac\Exporter\Compute\ComputeExporter::class, ['spec' => ['provider' => 'gcp', 'label' => 'test', 'machine_type' => 'e2-micro', 'zone' => 'us-central1-a', 'image' => 'debian-cloud/debian-11'], 'allowed_literals' => []]];
        yield 'compute-service-ecs' => [\Vortos\Iac\Exporter\ComputeService\ComputeServiceExporter::class, ['spec' => ['provider' => 'aws-ecs', 'label' => 'test', 'container_image' => 'app:latest', 'cpu' => 256, 'memory' => 512], 'allowed_literals' => []]];
        yield 'compute-service-cloudrun' => [\Vortos\Iac\Exporter\ComputeService\ComputeServiceExporter::class, ['spec' => ['provider' => 'gcp-cloud-run', 'label' => 'test', 'container_image' => 'app:latest'], 'allowed_literals' => []]];
    }

    private function rmrf(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
