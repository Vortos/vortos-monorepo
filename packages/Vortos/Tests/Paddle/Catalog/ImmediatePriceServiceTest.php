<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Catalog;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Catalog\ImmediatePriceService;
use Vortos\Paddle\Catalog\Operation\CreatePriceRequest;
use Vortos\Paddle\Catalog\Price;
use Vortos\Paddle\ValueObject\BillingInterval;
use Vortos\Paddle\ValueObject\Money;
use Vortos\Paddle\ValueObject\PaddlePriceId;
use Vortos\Paddle\ValueObject\PaddleProductId;

final class ImmediatePriceServiceTest extends TestCase
{
    private function makeSdkPrice(string $id = 'pri_test_123'): \Paddle\SDK\Entities\Price
    {
        return \Paddle\SDK\Entities\Price::from([
            'id'           => $id,
            'product_id'   => 'pro_test',
            'name'         => 'Monthly Plan',
            'description'  => 'Monthly subscription',
            'type'         => 'recurring',
            'billing_cycle' => ['interval' => 'month', 'frequency' => 1],
            'trial_period' => null,
            'tax_mode'     => 'account_setting',
            'unit_price'   => ['amount' => '1000', 'currency_code' => 'USD'],
            'unit_price_overrides' => [],
            'quantity'     => ['minimum' => 1, 'maximum' => 999],
            'status'       => 'active',
            'custom_data'  => null,
            'import_meta'  => null,
            'product'      => null,
            'created_at'   => '2024-01-01T00:00:00.000000Z',
            'updated_at'   => '2024-01-02T00:00:00.000000Z',
        ]);
    }

    public function test_create_returns_price_id(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkPrice('pri_abc'));

        $service = new ImmediatePriceService($client);
        $id      = $service->create(new CreatePriceRequest(
            productId:        PaddleProductId::of('pro_123'),
            description:      'Monthly Plan',
            unitPrice:        new Money(1000, 'USD'),
            billingInterval:  BillingInterval::Month,
            billingFrequency: 1,
        ));

        $this->assertInstanceOf(PaddlePriceId::class, $id);
        $this->assertSame('pri_abc', $id->value);
    }

    public function test_get_returns_mapped_price(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkPrice('pri_xyz'));

        $service = new ImmediatePriceService($client);
        $price   = $service->get(PaddlePriceId::of('pri_xyz'));

        $this->assertInstanceOf(Price::class, $price);
        $this->assertSame('pri_xyz', $price->id->value);
        $this->assertSame(BillingInterval::Month, $price->billingInterval);
        $this->assertSame(1000, $price->unitPrice->amount);
        $this->assertSame('USD', $price->unitPrice->currencyCode);
    }

    public function test_archive_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediatePriceService($client);
        $service->archive(PaddlePriceId::of('pri_123'));
    }

    public function test_list_returns_array_of_prices(): void
    {
        $collection = new \ArrayIterator([$this->makeSdkPrice('pri_1'), $this->makeSdkPrice('pri_2')]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service = new ImmediatePriceService($client);
        $prices  = $service->list();

        $this->assertCount(2, $prices);
        $this->assertContainsOnlyInstancesOf(Price::class, $prices);
    }
}
