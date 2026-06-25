<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Architecture;

use Vortos\OpsKit\Testing\AgnosticismLintTestCase;

/**
 * Stronger than the usual "provider names only under Driver\": core has **no**
 * provider driver at all (`null` is the only in-core driver and names no backend),
 * so no provider token may appear anywhere in this package.
 */
final class AnalyticsAgnosticismTest extends AgnosticismLintTestCase
{
    protected function packagePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
