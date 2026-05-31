<?php

declare(strict_types=1);

namespace Vortos\Paddle\Outbox;

use Vortos\Paddle\Catalog\Contract\ImmediateDiscountServiceInterface;
use Vortos\Paddle\Catalog\Contract\ImmediatePriceServiceInterface;
use Vortos\Paddle\Catalog\Contract\ImmediateProductServiceInterface;
use Vortos\Paddle\Catalog\Operation\CreateDiscountRequest;
use Vortos\Paddle\Catalog\Operation\CreatePriceRequest;
use Vortos\Paddle\Catalog\Operation\CreateProductRequest;
use Vortos\Paddle\Catalog\Operation\UpdateDiscountRequest;
use Vortos\Paddle\Catalog\Operation\UpdatePriceRequest;
use Vortos\Paddle\Catalog\Operation\UpdateProductRequest;
use Vortos\Paddle\Customer\Contract\ImmediateAddressServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateBusinessServiceInterface;
use Vortos\Paddle\Customer\Contract\ImmediateCustomerServiceInterface;
use Vortos\Paddle\Customer\Operation\CreateAddressRequest;
use Vortos\Paddle\Customer\Operation\CreateBusinessRequest;
use Vortos\Paddle\Customer\Operation\CreateCustomerRequest;
use Vortos\Paddle\Customer\Operation\UpdateAddressRequest;
use Vortos\Paddle\Customer\Operation\UpdateBusinessRequest;
use Vortos\Paddle\Customer\Operation\UpdateCustomerRequest;
use Vortos\Paddle\Outbox\Exception\UnknownOutboxOperationException;
use Vortos\Paddle\Subscription\Contract\ImmediateSubscriptionServiceInterface;
use Vortos\Paddle\Subscription\Operation\UpdateSubscriptionRequest;
use Vortos\Paddle\Transaction\Contract\ImmediateAdjustmentServiceInterface;
use Vortos\Paddle\Transaction\Contract\ImmediateTransactionServiceInterface;
use Vortos\Paddle\Transaction\Operation\AdjustmentItemRequest;
use Vortos\Paddle\Transaction\Operation\CreateCreditRequest;
use Vortos\Paddle\Transaction\Operation\CreateRefundRequest;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\TransactionItemRequest;
use Vortos\Paddle\Transaction\Operation\UpdateTransactionRequest;
use Vortos\Paddle\ValueObject\DiscountType;
use Vortos\Paddle\ValueObject\Money;
use Vortos\Paddle\ValueObject\PaddleAddressId;
use Vortos\Paddle\ValueObject\PaddleBusinessId;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddleDiscountId;
use Vortos\Paddle\ValueObject\PaddlePriceId;
use Vortos\Paddle\ValueObject\PaddleProductId;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;
use Vortos\Paddle\ValueObject\ProrationMode;

final class PaddleApiOutboxDispatcher implements PaddleOutboxDispatcherInterface
{
    public function __construct(
        private readonly ImmediateCustomerServiceInterface     $customers,
        private readonly ImmediateAddressServiceInterface      $addresses,
        private readonly ImmediateBusinessServiceInterface     $businesses,
        private readonly ImmediateTransactionServiceInterface  $transactions,
        private readonly ImmediateAdjustmentServiceInterface   $adjustments,
        private readonly ImmediateProductServiceInterface      $products,
        private readonly ImmediatePriceServiceInterface        $prices,
        private readonly ImmediateDiscountServiceInterface     $discounts,
        private readonly ImmediateSubscriptionServiceInterface $subscriptions,
    ) {}

    public function dispatch(string $operation, array $payload): void
    {
        match ($operation) {
            'customer.create'  => $this->customers->create(new CreateCustomerRequest(
                email:  $payload['email'],
                name:   $payload['name'],
                locale: $payload['locale'],
            )),
            'customer.update'  => $this->customers->update(
                PaddleCustomerId::of($payload['id']),
                new UpdateCustomerRequest(
                    name:   $payload['name'],
                    email:  $payload['email'],
                    locale: $payload['locale'],
                ),
            ),
            'customer.archive' => $this->customers->archive(
                PaddleCustomerId::of($payload['id']),
            ),

            'address.create'  => $this->addresses->create(new CreateAddressRequest(
                customerId:  PaddleCustomerId::of($payload['customerId']),
                countryCode: $payload['countryCode'],
                firstLine:   $payload['firstLine'],
            )),
            'address.update'  => $this->addresses->update(
                PaddleCustomerId::of($payload['customerId']),
                PaddleAddressId::of($payload['id']),
                new UpdateAddressRequest(
                    firstLine: $payload['firstLine'],
                    city:      $payload['city'],
                ),
            ),
            'address.archive' => $this->addresses->archive(
                PaddleCustomerId::of($payload['customerId']),
                PaddleAddressId::of($payload['id']),
            ),

            'business.create'  => $this->businesses->create(new CreateBusinessRequest(
                customerId: PaddleCustomerId::of($payload['customerId']),
                name:       $payload['name'],
            )),
            'business.update'  => $this->businesses->update(
                PaddleCustomerId::of($payload['customerId']),
                PaddleBusinessId::of($payload['id']),
                new UpdateBusinessRequest(name: $payload['name']),
            ),
            'business.archive' => $this->businesses->archive(
                PaddleCustomerId::of($payload['customerId']),
                PaddleBusinessId::of($payload['id']),
            ),

            'transaction.create' => $this->transactions->create(new CreateTransactionRequest(
                customerId: PaddleCustomerId::of($payload['customerId']),
                items:      array_map(
                    fn(array $i) => new TransactionItemRequest(PaddlePriceId::of($i['priceId']), $i['quantity']),
                    $payload['items'],
                ),
            )),
            'transaction.update' => $this->transactions->update(
                PaddleTransactionId::of($payload['id']),
                new UpdateTransactionRequest(
                    items: isset($payload['items']) && $payload['items'] !== null
                        ? array_map(
                            fn(array $i) => new TransactionItemRequest(PaddlePriceId::of($i['priceId']), $i['quantity']),
                            $payload['items'],
                        )
                        : null,
                    status: $payload['status'] ?? null,
                ),
            ),

            'adjustment.refund' => $this->adjustments->createRefund(new CreateRefundRequest(
                transactionId: PaddleTransactionId::of($payload['transactionId']),
                reason:        $payload['reason'],
                items:         array_map(
                    fn(array $i) => new AdjustmentItemRequest($i['lineItemId'], $i['amount']),
                    $payload['items'],
                ),
            )),
            'adjustment.credit' => $this->adjustments->createCredit(new CreateCreditRequest(
                transactionId: PaddleTransactionId::of($payload['transactionId']),
                reason:        $payload['reason'],
                items:         array_map(
                    fn(array $i) => new AdjustmentItemRequest($i['lineItemId'], $i['amount']),
                    $payload['items'],
                ),
            )),

            'product.create'  => $this->products->create(new CreateProductRequest(
                name:        $payload['name'],
                taxCategory: $payload['taxCategory'],
                description: $payload['description'],
                imageUrl:    $payload['imageUrl'],
            )),
            'product.update'  => $this->products->update(
                PaddleProductId::of($payload['id']),
                new UpdateProductRequest(
                    name:        $payload['name'],
                    description: $payload['description'],
                    imageUrl:    $payload['imageUrl'],
                    taxCategory: $payload['taxCategory'],
                ),
            ),
            'product.archive' => $this->products->archive(PaddleProductId::of($payload['id'])),

            'price.create'  => $this->prices->create(new CreatePriceRequest(
                productId:   PaddleProductId::of($payload['productId']),
                description: $payload['description'],
                unitPrice:   new Money($payload['amount'], $payload['currency']),
            )),
            'price.update'  => $this->prices->update(
                PaddlePriceId::of($payload['id']),
                new UpdatePriceRequest(
                    description: $payload['description'],
                    name:        $payload['name'],
                ),
            ),
            'price.archive' => $this->prices->archive(PaddlePriceId::of($payload['id'])),

            'discount.create'  => $this->discounts->create(new CreateDiscountRequest(
                type:         DiscountType::from($payload['type']),
                amount:       $payload['amount'],
                description:  $payload['description'],
                currencyCode: $payload['currency'],
            )),
            'discount.update'  => $this->discounts->update(
                PaddleDiscountId::of($payload['id']),
                new UpdateDiscountRequest(
                    description: $payload['description'],
                    amount:      $payload['amount'],
                    code:        $payload['code'],
                ),
            ),
            'discount.archive' => $this->discounts->archive(PaddleDiscountId::of($payload['id'])),

            'subscription.update'   => $this->subscriptions->update(
                PaddleSubscriptionId::of($payload['id']),
                new UpdateSubscriptionRequest(
                    prorationMode: isset($payload['prorationMode']) && $payload['prorationMode'] !== null
                        ? ProrationMode::from($payload['prorationMode'])
                        : null,
                    nextBilledAt: $payload['nextBilledAt'] ?? null,
                ),
            ),
            'subscription.pause'    => $this->subscriptions->pause(PaddleSubscriptionId::of($payload['id'])),
            'subscription.resume'   => $this->subscriptions->resume(PaddleSubscriptionId::of($payload['id'])),
            'subscription.cancel'   => $this->subscriptions->cancel(PaddleSubscriptionId::of($payload['id'])),
            'subscription.activate' => $this->subscriptions->activate(PaddleSubscriptionId::of($payload['id'])),

            default => throw UnknownOutboxOperationException::forOperation($operation),
        };
    }
}
