<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Catalog\ImmediateProductService;
use Vortos\Paddle\Catalog\Operation\CreateProductRequest;
use Vortos\Paddle\Catalog\Operation\UpdateProductRequest;
use Vortos\Paddle\Catalog\Product;
use Vortos\Paddle\ValueObject\PaddleProductId;
use Vortos\Paddle\ValueObject\ProductStatus;

final class ImmediateProductServiceTest extends TestCase
{
    private function makeSdkProduct(string $id = 'pro_test_123', string $status = 'active'): \Paddle\SDK\Entities\Product
    {
        return \Paddle\SDK\Entities\Product::from([
            'id'          => $id,
            'name'        => 'Test Product',
            'description' => 'A test product',
            'type'        => 'standard',
            'tax_category' => 'saas',
            'image_url'   => null,
            'custom_data' => null,
            'status'      => $status,
            'import_meta' => null,
            'prices'      => [],
            'created_at'  => '2024-01-01T00:00:00.000000Z',
            'updated_at'  => '2024-01-02T00:00:00.000000Z',
        ]);
    }

    public function test_create_returns_product_id(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkProduct('pro_abc'));

        $service = new ImmediateProductService($client);
        $id      = $service->create(new CreateProductRequest('My Product', 'saas'));

        $this->assertInstanceOf(PaddleProductId::class, $id);
        $this->assertSame('pro_abc', $id->value);
    }

    public function test_get_returns_mapped_product(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkProduct('pro_xyz'));

        $service = new ImmediateProductService($client);
        $product = $service->get(PaddleProductId::of('pro_xyz'));

        $this->assertInstanceOf(Product::class, $product);
        $this->assertSame('pro_xyz', $product->id->value);
        $this->assertSame('Test Product', $product->name);
        $this->assertSame('saas', $product->taxCategory);
        $this->assertSame(ProductStatus::Active, $product->status);
    }

    public function test_update_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateProductService($client);
        $service->update(
            PaddleProductId::of('pro_123'),
            new UpdateProductRequest(name: 'Updated Name')
        );
    }

    public function test_archive_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateProductService($client);
        $service->archive(PaddleProductId::of('pro_123'));
    }

    public function test_list_returns_array_of_products(): void
    {
        $collection = new \ArrayIterator([$this->makeSdkProduct('pro_1'), $this->makeSdkProduct('pro_2')]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service  = new ImmediateProductService($client);
        $products = $service->list();

        $this->assertCount(2, $products);
        $this->assertContainsOnlyInstancesOf(Product::class, $products);
    }

    public function test_archived_product_has_archived_status(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkProduct('pro_archived', 'archived'));

        $service = new ImmediateProductService($client);
        $product = $service->get(PaddleProductId::of('pro_archived'));

        $this->assertSame(ProductStatus::Archived, $product->status);
    }
}
