<?php
declare(strict_types=1);

namespace Vortos\Tests\Persistence;

use PHPUnit\Framework\TestCase;
use Vortos\Persistence\Read\InMemoryReadRepository;

final class TestReadRepository extends InMemoryReadRepository {}

final class InMemoryReadRepositoryTest extends TestCase
{
    private TestReadRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new TestReadRepository();
        $this->repo->seed('1', ['_id' => '1', 'name' => 'Alice', 'role' => 'admin']);
        $this->repo->seed('2', ['_id' => '2', 'name' => 'Bob', 'role' => 'user']);
        $this->repo->seed('3', ['_id' => '3', 'name' => 'Charlie', 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        $this->repo->clear();
    }

    public function test_find_by_id_returns_item(): void
    {
        $result = $this->repo->findById('1');
        $this->assertSame('Alice', $result['name']);
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $this->assertNull($this->repo->findById('999'));
    }

    public function test_find_by_criteria_returns_matching_items(): void
    {
        $admins = $this->repo->findByCriteria(['role' => 'admin']);
        $this->assertCount(2, $admins);
    }

    public function test_find_by_criteria_returns_empty_when_no_match(): void
    {
        $result = $this->repo->findByCriteria(['role' => 'superuser']);
        $this->assertEmpty($result);
    }

    public function test_count_by_criteria(): void
    {
        $this->assertSame(2, $this->repo->countByCriteria(['role' => 'admin']));
        $this->assertSame(1, $this->repo->countByCriteria(['role' => 'user']));
    }

    public function test_find_page_returns_page_result(): void
    {
        $page = $this->repo->findPage(criteria: [], limit: 2);
        $this->assertCount(2, $page->items);
        $this->assertTrue($page->hasMore);
        $this->assertNotNull($page->nextCursor);
    }

    public function test_find_page_second_page(): void
    {
        $first = $this->repo->findPage(criteria: [], limit: 2);
        $second = $this->repo->findPage(criteria: [], limit: 2, cursor: $first->nextCursor);
        $this->assertCount(1, $second->items);
        $this->assertFalse($second->hasMore);
    }

    public function test_clear_empties_store(): void
    {
        $this->repo->clear();
        $this->assertNull($this->repo->findById('1'));
        $this->assertSame(0, $this->repo->countByCriteria([]));
    }

    public function test_seed_adds_document(): void
    {
        $this->repo->seed('4', ['_id' => '4', 'name' => 'Dave', 'role' => 'user']);
        $this->assertSame('Dave', $this->repo->findById('4')['name']);
    }
}
