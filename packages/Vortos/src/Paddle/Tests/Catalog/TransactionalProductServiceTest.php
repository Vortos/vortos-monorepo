<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Catalog\Contract\ImmediateProductServiceInterface;
use Vortos\Paddle\Catalog\Product;
use Vortos\Paddle\ValueObject\ProductStatus;
use Vortos\Paddle\Catalog\Operation\CreateProductRequest;
use Vortos\Paddle\Catalog\Operation\UpdateProductRequest;
use Vortos\Paddle\Catalog\TransactionalProductService;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\ValueObject\PaddleProductId;

final class TransactionalProductServiceTest extends TestCase
{
    public function test_create_queues_outbox_entry(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
            ->method('queue')
            ->with('product.create', $this->arrayHasKey('name'));

        $reader  = $this->createMock(ImmediateProductServiceInterface::class);
        $service = new TransactionalProductService($outbox, $reader);

        $id = $service->create(new CreateProductRequest('My Product', 'saas'));

        $this->assertInstanceOf(PaddleProductId::class, $id);
    }

    public function test_update_queues_outbox_entry(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
            ->method('queue')
            ->with('product.update', $this->arrayHasKey('id'));

        $reader  = $this->createMock(ImmediateProductServiceInterface::class);
        $service = new TransactionalProductService($outbox, $reader);

        $service->update(PaddleProductId::of('pro_123'), new UpdateProductRequest(name: 'New Name'));
    }

    public function test_archive_queues_outbox_entry(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->expects($this->once())
            ->method('queue')
            ->with('product.archive', $this->arrayHasKey('id'));

        $reader  = $this->createMock(ImmediateProductServiceInterface::class);
        $service = new TransactionalProductService($outbox, $reader);

        $service->archive(PaddleProductId::of('pro_123'));
    }

    public function test_get_delegates_to_reader(): void
    {
        $fakeProduct = new Product(
            PaddleProductId::of('pro_123'),
            'Test',
            null,
            'saas',
            ProductStatus::Active,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-01'),
        );

        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $reader = $this->createMock(ImmediateProductServiceInterface::class);
        $reader->expects($this->once())->method('get')->willReturn($fakeProduct);

        $service = new TransactionalProductService($outbox, $reader);
        $service->get(PaddleProductId::of('pro_123'));
    }

    public function test_list_delegates_to_reader(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $reader = $this->createMock(ImmediateProductServiceInterface::class);
        $reader->expects($this->once())->method('list')->willReturn([]);

        $service = new TransactionalProductService($outbox, $reader);
        $result  = $service->list();

        $this->assertSame([], $result);
    }

    public function test_create_does_not_call_api(): void
    {
        $outbox = $this->createMock(PaddleOutboxWriterInterface::class);
        $outbox->method('queue');

        $reader = $this->createMock(ImmediateProductServiceInterface::class);
        $reader->expects($this->never())->method('create');

        $service = new TransactionalProductService($outbox, $reader);
        $service->create(new CreateProductRequest('Product', 'saas'));
    }
}
