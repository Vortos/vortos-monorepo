<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use Vortos\PersistenceOrm\Attribute\UsesOrmEntity;

/** A repository pointing at a global entity. */
#[UsesOrmEntity(GlobalEntityFixture::class)]
final class GlobalRepositoryFixture {}
