<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Attribute\InfraConfig;
use Vortos\Iac\Attribute\RegisterTerraformExporter;
use Vortos\Iac\DependencyInjection\Compiler\InfraConfigCompilerPass;
use Vortos\Iac\Definition\AbstractExporterDefinition;
use Vortos\Iac\Exporter\Kafka\KafkaProvider;
use Vortos\Iac\Exporter\Kafka\KafkaTopicsExporterDefinition;

final class PassTestDefinition extends AbstractExporterDefinition
{
    public function exporterClass(): string
    {
        return 'TestExporter';
    }

    public function compileSpec(ContainerBuilder $container): array
    {
        return ['ok' => true];
    }
}

#[InfraConfig]
final class ValidInfraConfig
{
    #[RegisterTerraformExporter]
    public function first(): PassTestDefinition
    {
        return PassTestDefinition::create('first')->outputFile('infra/first.tf.json');
    }

    public function notAnExporter(): string
    {
        return 'ignored';
    }
}

#[InfraConfig]
final class CtorDependencyInfraConfig
{
    public function __construct(private readonly \stdClass $dep) {}
}

#[InfraConfig]
final class WrongReturnInfraConfig
{
    #[RegisterTerraformExporter]
    public function broken(): string
    {
        return 'not a definition';
    }
}

#[InfraConfig]
final class DuplicateNameInfraConfig
{
    #[RegisterTerraformExporter]
    public function a(): PassTestDefinition
    {
        return PassTestDefinition::create('dup')->outputFile('infra/a.tf.json');
    }

    #[RegisterTerraformExporter]
    public function b(): PassTestDefinition
    {
        return PassTestDefinition::create('dup')->outputFile('infra/b.tf.json');
    }
}

#[InfraConfig]
final class DuplicateFileInfraConfig
{
    #[RegisterTerraformExporter]
    public function a(): PassTestDefinition
    {
        return PassTestDefinition::create('one')->outputFile('infra/same.tf.json');
    }

    #[RegisterTerraformExporter]
    public function b(): PassTestDefinition
    {
        return PassTestDefinition::create('two')->outputFile('infra/same.tf.json');
    }
}

#[InfraConfig]
final class TraversalPathInfraConfig
{
    #[RegisterTerraformExporter]
    public function escape(): PassTestDefinition
    {
        return PassTestDefinition::create('escape')->outputFile('../outside.tf.json');
    }
}

#[InfraConfig]
final class MissingOutputInfraConfig
{
    #[RegisterTerraformExporter]
    public function nowhere(): PassTestDefinition
    {
        return PassTestDefinition::create('nowhere');
    }
}

#[InfraConfig]
final class MissingMessagingInfraConfig
{
    #[RegisterTerraformExporter]
    public function kafka(): KafkaTopicsExporterDefinition
    {
        return KafkaTopicsExporterDefinition::create('kafka')
            ->provider(KafkaProvider::Kafka)
            ->outputFile('infra/kafka.tf.json');
    }
}

final class InfraConfigCompilerPassTest extends TestCase
{
    private function compile(string ...$configClasses): ContainerBuilder
    {
        $container = new ContainerBuilder();

        foreach ($configClasses as $class) {
            $container->register($class, $class)->addTag('vortos.infra_config');
        }

        (new InfraConfigCompilerPass())->process($container);

        return $container;
    }

    public function test_compiles_definitions_to_parameter(): void
    {
        $container = $this->compile(ValidInfraConfig::class);
        $exports = $container->getParameterBag()->resolveValue($container->getParameter('vortos.iac.exports'));

        $this->assertCount(1, $exports);
        $this->assertSame('first', $exports[0]['name']);
        $this->assertSame('infra/first.tf.json', $exports[0]['output_file']);
        $this->assertSame('infra/first_variables.tf.json', $exports[0]['variables_file']);
        $this->assertSame(['ok' => true], $exports[0]['spec']);
    }

    public function test_no_configs_compiles_empty_parameter(): void
    {
        $this->assertSame([], $this->compile()->getParameter('vortos.iac.exports'));
    }

    public function test_constructor_dependencies_fail_the_build(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must have no constructor dependencies');

        $this->compile(CtorDependencyInfraConfig::class);
    }

    public function test_non_definition_return_fails_the_build(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must return an AbstractExporterDefinition');

        $this->compile(WrongReturnInfraConfig::class);
    }

    public function test_duplicate_names_fail_the_build(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Duplicate exporter name 'dup'");

        $this->compile(DuplicateNameInfraConfig::class);
    }

    public function test_duplicate_output_files_fail_the_build(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("both write 'infra/same.tf.json'");

        $this->compile(DuplicateFileInfraConfig::class);
    }

    public function test_path_traversal_fails_the_build(): void
    {
        $this->expectException(\Vortos\Iac\Exception\PathViolationException::class);

        $this->compile(TraversalPathInfraConfig::class);
    }

    public function test_missing_output_file_fails_the_build(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares no outputFile()');

        $this->compile(MissingOutputInfraConfig::class);
    }

    public function test_kafka_exporter_without_messaging_package_fails_the_build(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires the messaging package');

        $this->compile(MissingMessagingInfraConfig::class);
    }
}
