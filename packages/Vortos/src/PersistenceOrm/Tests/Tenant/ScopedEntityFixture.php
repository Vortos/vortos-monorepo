<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use Vortos\Tenant\Attribute\TenantScoped;

/** A tenant-scoped entity fixture. */
#[TenantScoped]
final class ScopedEntityFixture
{
    public ?string $tenantId = null;
}
