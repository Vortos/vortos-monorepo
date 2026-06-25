<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Architecture;

use Vortos\OpsKit\Testing\AgnosticismLintTestCase;

final class IacAgnosticismTest extends AgnosticismLintTestCase
{
    protected function packagePath(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function exemptNamespaceSegments(): array
    {
        return ['Driver', 'Exporter', 'Mapper', 'Terraform'];
    }

    protected function exemptPathFragments(): array
    {
        return [
            '/Tests/',
            '/config/',
            '/DependencyInjection/',
            '/Attribute/',
            '/Terraform/',
            '/Export/',
            '/Exporter/',
            '/Lifecycle/StateBackend/',
            '/Lifecycle/IacLifecycleService.php',
        ];
    }
}
