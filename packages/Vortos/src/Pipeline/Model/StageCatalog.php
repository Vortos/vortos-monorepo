<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final class StageCatalog
{
    /** @return list<StageKind> */
    public static function standard(): array
    {
        return [
            StageKind::Test,
            StageKind::StaticAnalysis,
            StageKind::Agnosticism,
            StageKind::Security,
            StageKind::MigrationDryRun,
            StageKind::Build,
            StageKind::IacPlan,
            StageKind::Deploy,
        ];
    }
}
