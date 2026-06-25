<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Schema\SchemaFingerprint;

final readonly class CurrentDeployState
{
    /** @var list<string> */
    public array $pendingContractMigrations;

    /**
     * @param list<string> $pendingContractMigrations
     */
    public function __construct(
        public ActiveColor $activeColor,
        public string $currentDigest,
        public SchemaFingerprint $appliedFingerprint,
        array $pendingContractMigrations = [],
    ) {
        $this->pendingContractMigrations = $pendingContractMigrations;
    }

    public function pendingContract(): bool
    {
        return $this->pendingContractMigrations !== [];
    }

    public static function firstDeploy(): self
    {
        return new self(
            activeColor: ActiveColor::None,
            currentDigest: '',
            appliedFingerprint: SchemaFingerprint::empty(),
            pendingContractMigrations: [],
        );
    }

    public function isFirstDeploy(): bool
    {
        return $this->activeColor === ActiveColor::None && $this->currentDigest === '';
    }
}
