<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use Vortos\Tenant\Attribute\TenantScoped;

/** A tenant-scoped entity with a custom column. */
#[TenantScoped(column: 'org_id')]
final class CustomColumnEntityFixture
{
    public ?string $orgId = null;
}
