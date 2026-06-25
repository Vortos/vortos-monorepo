<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Architecture;

use Vortos\OpsKit\Testing\AgnosticismLintTestCase;

/**
 * No provider name (r2/s3/cloudflare/…) may appear outside a `Driver/` namespace.
 * Engine identities (`postgres`, `mongo`) are not providers and are allowed in core.
 */
final class BackupAgnosticismTest extends AgnosticismLintTestCase
{
    protected function packagePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
