<?php

declare(strict_types=1);

namespace Vortos\Iac\Attribute;

use Attribute;

/**
 * Marks a class as an infrastructure-export configuration provider.
 *
 * Classes marked with this attribute are discovered by the compiler pass and
 * inspected for methods annotated with RegisterTerraformExporter. Each such
 * method is called to retrieve an exporter definition, which is compiled into
 * a static export spec at container build time.
 *
 * Infra export configuration is a deployment concern, not a bounded-context
 * concern — the canonical home is a single class in
 * src/Shared/Infrastructure/Iac/. Multiple classes are supported (the pass
 * merges them), but resource *shape* stays where it is already declared
 * (MessagingConfig transports etc.); InfraConfig only chooses providers and
 * output files.
 *
 * The class must have no constructor dependencies — it is instantiated by
 * the compiler pass via reflection.
 *
 * Example:
 *   #[InfraConfig]
 *   final class AppInfraConfig
 *   {
 *       #[RegisterTerraformExporter]
 *       public function kafkaTopics(): KafkaTopicsExporterDefinition { ... }
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class InfraConfig
{
}
