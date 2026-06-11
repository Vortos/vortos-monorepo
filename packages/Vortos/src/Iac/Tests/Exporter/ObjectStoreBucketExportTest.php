<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Attribute\InfraConfig;
use Vortos\Iac\Attribute\RegisterTerraformExporter;
use Vortos\Iac\DependencyInjection\Compiler\InfraConfigCompilerPass;
use Vortos\Iac\Exporter\ObjectStore\ObjectStoreBucketExporter;
use Vortos\Iac\Exporter\ObjectStore\ObjectStoreBucketExporterDefinition;
use Vortos\Iac\Exporter\ObjectStore\ObjectStoreProvider;

#[InfraConfig]
final class R2BucketInfraFixture
{
    #[RegisterTerraformExporter]
    public function bucket(): ObjectStoreBucketExporterDefinition
    {
        return ObjectStoreBucketExporterDefinition::create('app-bucket')
            ->provider(ObjectStoreProvider::CloudflareR2)
            ->outputFile('infra/bucket.tf.json');
    }
}

#[InfraConfig]
final class AwsBucketInfraFixture
{
    #[RegisterTerraformExporter]
    public function bucket(): ObjectStoreBucketExporterDefinition
    {
        return ObjectStoreBucketExporterDefinition::create('app-bucket')
            ->provider(ObjectStoreProvider::Aws)
            ->outputFile('infra/bucket.tf.json');
    }
}

final class ObjectStoreBucketExportTest extends TestCase
{
    private function compile(string $fixture, array $parameters): array
    {
        $container = new ContainerBuilder();

        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
        }

        $container->register($fixture, $fixture)->addTag('vortos.infra_config');
        (new InfraConfigCompilerPass())->process($container);

        return $container->getParameterBag()->resolveValue($container->getParameter('vortos.iac.exports'));
    }

    public function test_r2_bucket_with_env_account_id(): void
    {
        $exports = $this->compile(R2BucketInfraFixture::class, [
            'vortos_object_store.bucket.name' => 'app-uploads',
            'vortos_object_store.region' => 'auto',
            'vortos_object_store.client.account_id' => '%env(R2_ACCOUNT_ID)%',
        ]);

        $decoded = json_decode((new ObjectStoreBucketExporter())->export($exports[0])->render(includeVariables: false), true);
        $bucket = $decoded['resource']['cloudflare_r2_bucket']['app_bucket'];

        $this->assertSame('app-uploads', $bucket['name']);
        $this->assertSame('${var.r2_account_id}', $bucket['account_id']);
        $this->assertSame('auto', $bucket['location']);
        $this->assertSame('cloudflare/cloudflare', $decoded['terraform']['required_providers']['cloudflare']['source']);
    }

    public function test_aws_bucket(): void
    {
        $exports = $this->compile(AwsBucketInfraFixture::class, [
            'vortos_object_store.bucket.name' => 'app-uploads',
            'vortos_object_store.region' => 'eu-central-1',
        ]);

        $decoded = json_decode((new ObjectStoreBucketExporter())->export($exports[0])->render(includeVariables: false), true);

        $this->assertSame('app-uploads', $decoded['resource']['aws_s3_bucket']['app_bucket']['bucket']);
        $this->assertSame('hashicorp/aws', $decoded['terraform']['required_providers']['aws']['source']);
    }

    public function test_missing_object_store_package_fails_the_build(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires the vortos-object-store package');

        $this->compile(AwsBucketInfraFixture::class, []);
    }

    public function test_r2_without_account_id_fails_the_build(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires vortos_object_store.client.account_id');

        $this->compile(R2BucketInfraFixture::class, [
            'vortos_object_store.bucket.name' => 'app-uploads',
            'vortos_object_store.region' => 'auto',
            'vortos_object_store.client.account_id' => '',
        ]);
    }
}
