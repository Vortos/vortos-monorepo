<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use Vortos\OpsKit\Testing\AgnosticismLintTestCase;

final class DeployAgnosticismTest extends AgnosticismLintTestCase
{
    protected function packagePath(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function exemptPathFragments(): array
    {
        return ['/Tests/', '/config/', '/DependencyInjection/'];
    }
}
