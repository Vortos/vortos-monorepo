<?php

declare(strict_types=1);

namespace Vortos\Release\Tests\Architecture;

use Vortos\OpsKit\Testing\AgnosticismLintTestCase;

final class ReleaseAgnosticismTest extends AgnosticismLintTestCase
{
    protected function packagePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
