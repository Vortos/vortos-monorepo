<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Catalog;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Catalog\Discount;
use Vortos\Paddle\Catalog\ImmediateDiscountService;
use Vortos\Paddle\Catalog\Operation\CreateDiscountRequest;
use Vortos\Paddle\ValueObject\DiscountStatus;
use Vortos\Paddle\ValueObject\DiscountType;
use Vortos\Paddle\ValueObject\PaddleDiscountId;

final class ImmediateDiscountServiceTest extends TestCase
{
    private function makeSdkDiscount(string $id = 'dsc_test_123', string $status = 'active'): \Paddle\SDK\Entities\Discount
    {
        return \Paddle\SDK\Entities\Discount::from([
            'id'                          => $id,
            'status'                      => $status,
            'description'                 => '10% off',
            'enabled_for_checkout'        => true,
            'code'                        => 'SAVE10',
            'type'                        => 'percentage',
            'amount'                      => '10',
            'currency_code'               => null,
            'recur'                       => false,
            'maximum_recurring_intervals' => null,
            'usage_limit'                 => null,
            'restrict_to'                 => null,
            'expires_at'                  => null,
            'times_used'                  => 0,
            'created_at'                  => '2024-01-01T00:00:00.000000Z',
            'updated_at'                  => '2024-01-02T00:00:00.000000Z',
            'custom_data'                 => null,
            'import_meta'                 => null,
            'mode'                        => 'standard',
            'discount_group_id'           => null,
            'discount_group'              => null,
        ]);
    }

    public function test_create_returns_discount_id(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkDiscount('dsc_abc'));

        $service = new ImmediateDiscountService($client);
        $id      = $service->create(new CreateDiscountRequest(
            type:         DiscountType::Percentage,
            amount:       '10',
            description:  '10% off',
            currencyCode: 'USD',
        ));

        $this->assertInstanceOf(PaddleDiscountId::class, $id);
        $this->assertSame('dsc_abc', $id->value);
    }

    public function test_get_returns_mapped_discount(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkDiscount('dsc_xyz'));

        $service  = new ImmediateDiscountService($client);
        $discount = $service->get(PaddleDiscountId::of('dsc_xyz'));

        $this->assertInstanceOf(Discount::class, $discount);
        $this->assertSame('dsc_xyz', $discount->id->value);
        $this->assertSame(DiscountType::Percentage, $discount->type);
        $this->assertSame(DiscountStatus::Active, $discount->status);
    }

    public function test_archive_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateDiscountService($client);
        $service->archive(PaddleDiscountId::of('dsc_123'));
    }

    public function test_list_returns_array_of_discounts(): void
    {
        $collection = new \ArrayIterator([
            $this->makeSdkDiscount('dsc_1'),
            $this->makeSdkDiscount('dsc_2'),
        ]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service   = new ImmediateDiscountService($client);
        $discounts = $service->list();

        $this->assertCount(2, $discounts);
        $this->assertContainsOnlyInstancesOf(Discount::class, $discounts);
    }
}
