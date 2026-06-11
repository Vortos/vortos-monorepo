<?php

declare(strict_types=1);

namespace Vortos\Iac\Export;

use Vortos\Iac\Terraform\TerraformDocument;

/**
 * Runtime half of an exporter: turns one compiled export entry (the static
 * spec produced by the definition at container build time) into a Terraform
 * document. Implementations must be pure — no I/O, no network, no env reads.
 */
interface ExporterInterface
{
    /** @param array<string, mixed> $entry one entry of the vortos.iac.exports parameter */
    public function export(array $entry): TerraformDocument;

    /** Number of resources this entry will emit (for the zero-resource warning). */
    public function countResources(array $entry): int;
}
