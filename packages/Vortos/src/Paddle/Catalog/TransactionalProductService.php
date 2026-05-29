<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Vortos\Paddle\Catalog\Contract\ImmediateProductServiceInterface;
use Vortos\Paddle\Catalog\Contract\ProductServiceInterface;
use Vortos\Paddle\Catalog\Operation\CreateProductRequest;
use Vortos\Paddle\Catalog\Operation\UpdateProductRequest;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\ValueObject\PaddleProductId;

final class TransactionalProductService implements ProductServiceInterface
{
    public function __construct(
        private readonly PaddleOutboxWriterInterface    $outbox,
        private readonly ImmediateProductServiceInterface $reader,
    ) {}

    public function create(CreateProductRequest $request): PaddleProductId
    {
        $id = PaddleProductId::of('outbox-' . bin2hex(random_bytes(8)));

        $this->outbox->queue('product.create', [
            'name'        => $request->name,
            'taxCategory' => $request->taxCategory,
            'description' => $request->description,
            'imageUrl'    => $request->imageUrl,
        ]);

        return $id;
    }

    public function get(PaddleProductId $id): Product
    {
        return $this->reader->get($id);
    }

    public function update(PaddleProductId $id, UpdateProductRequest $request): void
    {
        $this->outbox->queue('product.update', [
            'id'          => $id->value,
            'name'        => $request->name,
            'description' => $request->description,
            'imageUrl'    => $request->imageUrl,
            'taxCategory' => $request->taxCategory,
        ]);
    }

    public function archive(PaddleProductId $id): void
    {
        $this->outbox->queue('product.archive', ['id' => $id->value]);
    }

    public function list(): array
    {
        return $this->reader->list();
    }
}
