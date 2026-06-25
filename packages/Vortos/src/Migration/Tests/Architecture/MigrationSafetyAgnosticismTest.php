<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Architecture;

use Vortos\OpsKit\Testing\AgnosticismLintTestCase;

final class MigrationSafetyAgnosticismTest extends AgnosticismLintTestCase
{
    protected function packagePath(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function exemptNamespaceSegments(): array
    {
        return ['Driver', 'Tests'];
    }
}
