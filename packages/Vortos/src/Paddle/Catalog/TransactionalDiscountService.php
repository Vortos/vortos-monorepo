<?php

declare(strict_types=1);

namespace Vortos\Paddle\Catalog;

use Vortos\Paddle\Catalog\Contract\DiscountServiceInterface;
use Vortos\Paddle\Catalog\Contract\ImmediateDiscountServiceInterface;
use Vortos\Paddle\Catalog\Operation\CreateDiscountRequest;
use Vortos\Paddle\Catalog\Operation\UpdateDiscountRequest;
use Vortos\Paddle\Outbox\PaddleOutboxWriterInterface;
use Vortos\Paddle\ValueObject\PaddleDiscountId;

final class TransactionalDiscountService implements DiscountServiceInterface
{
    public function __construct(
        private readonly PaddleOutboxWriterInterface      $outbox,
        private readonly ImmediateDiscountServiceInterface $reader,
    ) {}

    public function create(CreateDiscountRequest $request): PaddleDiscountId
    {
        $id = PaddleDiscountId::of('outbox-' . bin2hex(random_bytes(8)));

        $this->outbox->queue('discount.create', [
            'type'        => $request->type->value,
            'amount'      => $request->amount,
            'description' => $request->description,
            'currency'    => $request->currencyCode,
        ]);

        return $id;
    }

    public function get(PaddleDiscountId $id): Discount
    {
        return $this->reader->get($id);
    }

    public function update(PaddleDiscountId $id, UpdateDiscountRequest $request): void
    {
        $this->outbox->queue('discount.update', [
            'id'          => $id->value,
            'description' => $request->description,
            'amount'      => $request->amount,
            'code'        => $request->code,
        ]);
    }

    public function archive(PaddleDiscountId $id): void
    {
        $this->outbox->queue('discount.archive', ['id' => $id->value]);
    }

    public function list(): array
    {
        return $this->reader->list();
    }
}
