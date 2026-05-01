<?php

declare(strict_types=1);

namespace Vortos\Tests\PersistenceOrm;

use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use Vortos\Domain\Identity\AggregateId;
use Vortos\PersistenceOrm\Aggregate\OrmAggregateRoot;

final class OrmTestId extends AggregateId {}

#[ORM\Entity]
final class OrmTestAggregate extends OrmAggregateRoot
{
    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private string $id;

    public function __construct()
    {
        $this->id = (string) OrmTestId::generate();
    }

    public function getId(): OrmTestId
    {
        return OrmTestId::fromString($this->id);
    }
}

final class OrmAggregateRootTest extends TestCase
{
    public function test_version_starts_at_zero(): void
    {
        $this->assertSame(0, (new OrmTestAggregate())->getVersion());
    }

    public function test_increment_version_increments_orm_version(): void
    {
        $agg = new OrmTestAggregate();
        $agg->incrementVersion();
        $agg->incrementVersion();
        $this->assertSame(2, $agg->getVersion());
    }

    public function test_restore_version_sets_orm_version(): void
    {
        $agg = new OrmTestAggregate();
        // restoreVersion is protected — call via a subclass method
        $agg->incrementVersion(); // 1
        $agg->incrementVersion(); // 2

        // Use reflection to call restoreVersion
        $ref = new \ReflectionMethod(OrmAggregateRoot::class, 'restoreVersion');
        $ref->invoke($agg, 7);

        $this->assertSame(7, $agg->getVersion());
    }

    public function test_has_mapped_superclass_attribute(): void
    {
        $attrs = (new \ReflectionClass(OrmAggregateRoot::class))
            ->getAttributes(ORM\MappedSuperclass::class);

        $this->assertCount(1, $attrs);
    }

    public function test_orm_version_field_has_version_and_column_attributes(): void
    {
        $prop = new \ReflectionProperty(OrmAggregateRoot::class, 'ormVersion');

        $this->assertCount(1, $prop->getAttributes(ORM\Version::class));
        $this->assertCount(1, $prop->getAttributes(ORM\Column::class));
    }

    public function test_extends_aggregate_root(): void
    {
        $this->assertInstanceOf(
            \Vortos\Domain\Aggregate\AggregateRoot::class,
            new OrmTestAggregate(),
        );
    }

    public function test_domain_events_still_work(): void
    {
        $agg = new OrmTestAggregate();
        $this->assertEmpty($agg->pullDomainEvents());
        $this->assertFalse($agg->hasDomainEvents());
    }
}
