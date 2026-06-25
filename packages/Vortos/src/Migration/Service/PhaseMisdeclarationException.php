<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Schema\MigrationPhase;

final class PhaseMisdeclarationException extends \RuntimeException
{
    /** @param list<string> $destructiveTokens */
    public function __construct(
        public readonly string $migrationId,
        public readonly MigrationPhase $declaredPhase,
        public readonly array $destructiveTokens,
    ) {
        parent::__construct(sprintf(
            'Migration "%s" is declared as "%s" but contains destructive SQL operations [%s]. '
            . 'A migration with destructive operations must be declared as #[DeployPhase(MigrationPhase::Contract)].',
            $migrationId,
            $declaredPhase->value,
            implode(', ', $destructiveTokens),
        ));
    }
}
