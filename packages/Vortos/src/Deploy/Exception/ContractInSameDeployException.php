<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

final class ContractInSameDeployException extends DeployException
{
    /** @var list<string> */
    public readonly array $offendingMigrations;

    /** @param list<string> $offendingMigrations */
    public function __construct(array $offendingMigrations)
    {
        $this->offendingMigrations = $offendingMigrations;
        $ids = implode(', ', $offendingMigrations);

        parent::__construct(sprintf(
            'Deploy refused: pending contract migration(s) [%s] cannot be applied in the same deploy as a new-color bring-up. '
            . 'Ship contract in a later deploy after the soak/flag gate confirms zero old-code references. '
            . 'Current pending: %s.',
            $ids,
            $ids,
        ));
    }
}
