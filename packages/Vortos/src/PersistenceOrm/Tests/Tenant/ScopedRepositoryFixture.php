<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use Vortos\PersistenceOrm\Attribute\UsesOrmEntity;

/** A repository pointing at a scoped entity (for compiler-pass discovery). */
#[UsesOrmEntity(ScopedEntityFixture::class)]
final class ScopedRepositoryFixture {}
