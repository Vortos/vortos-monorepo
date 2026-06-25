<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Architecture;

use Vortos\OpsKit\Testing\AgnosticismLintTestCase;

/**
 * The kit eats its own dog food: its production source must contain no provider
 * names outside a Driver\ namespace. (Tests/ — which holds the lint fixtures that
 * deliberately leak — is excluded, as is any composition root.)
 */
final class OpsKitAgnosticismTest extends AgnosticismLintTestCase
{
    protected function packagePath(): string
    {
        return \dirname(__DIR__, 2);
    }
}
