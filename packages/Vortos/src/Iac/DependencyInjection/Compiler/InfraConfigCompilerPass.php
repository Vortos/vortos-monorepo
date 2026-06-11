<?php

declare(strict_types=1);

namespace Vortos\Iac\DependencyInjection\Compiler;

use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Iac\Attribute\RegisterTerraformExporter;
use Vortos\Iac\Definition\AbstractExporterDefinition;

/**
 * Discovers #[InfraConfig] classes, invokes their
 * #[RegisterTerraformExporter] methods, and compiles every definition into
 * the static 'vortos.iac.exports' parameter.
 *
 * Everything that can be wrong — duplicate names, duplicate output paths,
 * invalid paths, missing providers, unresolvable placeholders — fails HERE,
 * at container build time, never during the export run.
 *
 * Must run after the framework passes that publish the parameters
 * definitions read (MessagingConfigCompilerPass etc.) and before parameter
 * resolution, while env placeholders are still raw strings — that rawness
 * is what lets PlaceholderTranslator turn them into Terraform variables
 * without ever touching a live env value.
 */
final class InfraConfigCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $entries = [];
        $names = [];
        $files = [];

        foreach (array_keys($container->findTaggedServiceIds('vortos.infra_config')) as $serviceId) {
            $className = $container->getDefinition($serviceId)->getClass();
            $reflClass = new ReflectionClass($className);
            $constructor = $reflClass->getConstructor();

            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw new LogicException(
                    "InfraConfig class '{$className}' must have no constructor dependencies. It is instantiated by the compiler pass via reflection."
                );
            }

            $configInstance = $reflClass->newInstance();

            foreach ($reflClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(RegisterTerraformExporter::class) === []) {
                    continue;
                }

                $definition = $method->invoke($configInstance);

                if (!$definition instanceof AbstractExporterDefinition) {
                    throw new LogicException(
                        "Method '{$method->getName()}' on '{$className}' marked with #[RegisterTerraformExporter] must return an AbstractExporterDefinition."
                    );
                }

                $entry = $definition->toExportEntry($container);

                if (isset($names[$entry['name']])) {
                    throw new LogicException(
                        "Duplicate exporter name '{$entry['name']}' (declared on '{$names[$entry['name']]}' and '{$className}')."
                    );
                }
                $names[$entry['name']] = $className;

                foreach ([$entry['output_file'], $entry['variables_file']] as $file) {
                    if (isset($files[$file])) {
                        throw new LogicException(
                            "Exporters '{$files[$file]}' and '{$entry['name']}' both write '{$file}' — output files must be unique."
                        );
                    }
                    $files[$file] = $entry['name'];
                }

                $entries[] = $entry;
            }
        }

        // Escape '%' so literal values survive parameter resolution untouched.
        $container->setParameter('vortos.iac.exports', $container->getParameterBag()->escapeValue($entries));
    }
}
