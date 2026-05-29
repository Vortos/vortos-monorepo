<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Vortos\Paddle\Catalog\Contract\ImmediatePriceServiceInterface;
use Vortos\Paddle\Catalog\Contract\PriceServiceInterface;
use Vortos\Paddle\Catalog\Operation\CreatePriceRequest;
use Vortos\Paddle\Catalog\Operation\UpdatePriceRequest;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\ValueObject\PaddlePriceId;

final class TransactionalPriceService implements PriceServiceInterface
{
    public function __construct(
        private readonly PaddleOutboxWriterInterface  $outbox,
        private readonly ImmediatePriceServiceInterface $reader,
    ) {}

    public function create(CreatePriceRequest $request): PaddlePriceId
    {
        $id = PaddlePriceId::of('outbox-' . bin2hex(random_bytes(8)));

        $this->outbox->queue('price.create', [
            'productId'   => $request->productId->value,
            'description' => $request->description,
            'amount'      => $request->unitPrice->amount,
            'currency'    => $request->unitPrice->currencyCode,
        ]);

        return $id;
    }

    public function get(PaddlePriceId $id): Price
    {
        return $this->reader->get($id);
    }

    public function update(PaddlePriceId $id, UpdatePriceRequest $request): void
    {
        $this->outbox->queue('price.update', [
            'id'          => $id->value,
            'description' => $request->description,
            'name'        => $request->name,
        ]);
    }

    public function archive(PaddlePriceId $id): void
    {
        $this->outbox->queue('price.archive', ['id' => $id->value]);
    }

    public function list(): array
    {
        return $this->reader->list();
    }
}
