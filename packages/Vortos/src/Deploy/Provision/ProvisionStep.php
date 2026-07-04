<?php

declare(strict_types=1);

namespace Vortos\Deploy\Provision;

/**
 * One step of the first-deploy provisioning plan: a console command to run, with its arguments and
 * a human description.
 */
final readonly class ProvisionStep
{
    /** @param list<string> $args */
    public function __construct(
        public string $command,
        public array $args,
        public string $description,
    ) {}
}
