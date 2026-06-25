<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Architecture;

use Vortos\OpsKit\Testing\AgnosticismLintTestCase;

final class SecretsAgnosticismTest extends AgnosticismLintTestCase
{
    protected function packagePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
