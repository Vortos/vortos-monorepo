<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\StageCatalog;
use Vortos\Pipeline\Model\StageKind;

final class StageCatalogTest extends TestCase
{
    public function test_standard_returns_correct_order(): void
    {
        $standard = StageCatalog::standard();

        $this->assertSame([
            StageKind::Test,
            StageKind::StaticAnalysis,
            StageKind::Agnosticism,
            StageKind::Security,
            StageKind::MigrationDryRun,
            StageKind::Build,
            StageKind::IacPlan,
            StageKind::Deploy,
        ], $standard);
    }

    public function test_standard_does_not_include_split(): void
    {
        $standard = StageCatalog::standard();

        $this->assertNotContains(StageKind::Split, $standard);
    }
}
