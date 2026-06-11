<?php

declare(strict_types=1);

namespace Vortos\Iac\Attribute;

use Attribute;

/**
 * Marks a method on an InfraConfig class as a Terraform exporter provider.
 *
 * The method must return an AbstractExporterDefinition. It is invoked at
 * container compile time; the definition's spec is compiled from container
 * parameters then and there, so misconfiguration (bad paths, unknown
 * providers, unresolvable placeholders) fails the build — never the
 * production export run.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RegisterTerraformExporter
{
}
