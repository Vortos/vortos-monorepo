<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Architecture;

use Vortos\OpsKit\Testing\AgnosticismLintTestCase;

final class MigrationAgnosticismTest extends AgnosticismLintTestCase
{
    protected function packagePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
