<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Catalog\Operation\PricePreviewItem;
use Vortos\Paddle\Catalog\Operation\PricePreviewRequest;
use Vortos\Paddle\Catalog\PricePreviewResult;
use Vortos\Paddle\Catalog\PricePreviewService;
use Vortos\Paddle\ValueObject\PaddlePriceId;

final class PricePreviewServiceTest extends TestCase
{
    private function makeSdkPreviewResult(): \Paddle\SDK\Entities\PricePreview
    {
        return \Paddle\SDK\Entities\PricePreview::from([
            'customer_id'               => null,
            'address_id'                => null,
            'business_id'               => null,
            'currency_code'             => 'USD',
            'discount_id'               => null,
            'address'                   => null,
            'customer_ip_address'       => null,
            'available_payment_methods' => [],
            'details'                   => [
                'line_items' => [
                    [
                        'price'   => [
                            'id'           => 'pri_test',
                            'product_id'   => 'pro_test',
                            'name'         => 'Monthly',
                            'description'  => 'Monthly plan',
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
                            'updated_at'   => '2024-01-01T00:00:00.000000Z',
                        ],
                        'quantity'     => 2,
                        'tax_rate'     => '0.20',
                        'unit_totals'  => ['subtotal' => '1000', 'discount' => '0', 'tax' => '200', 'total' => '1200'],
                        'formatted_unit_totals' => ['subtotal' => '$10.00', 'discount' => '$0.00', 'tax' => '$2.00', 'total' => '$12.00'],
                        'totals'       => ['subtotal' => '2000', 'discount' => '0', 'tax' => '400', 'total' => '2400'],
                        'formatted_totals' => ['subtotal' => '$20.00', 'discount' => '$0.00', 'tax' => '$4.00', 'total' => '$24.00'],
                        'product'      => [
                            'id'          => 'pro_test',
                            'name'        => 'Test Product',
                            'description' => null,
                            'type'        => 'standard',
                            'tax_category' => 'saas',
                            'image_url'   => null,
                            'custom_data' => null,
                            'status'      => 'active',
                            'import_meta' => null,
                            'prices'      => [],
                            'created_at'  => '2024-01-01T00:00:00.000000Z',
                            'updated_at'  => '2024-01-01T00:00:00.000000Z',
                        ],
                        'discounts' => [],
                    ],
                ],
            ],
        ]);
    }

    public function test_preview_returns_result_with_correct_currency(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkPreviewResult());

        $service = new PricePreviewService($client);
        $result  = $service->preview(new PricePreviewRequest(
            items: [new PricePreviewItem(PaddlePriceId::of('pri_test'), 2)],
        ));

        $this->assertInstanceOf(PricePreviewResult::class, $result);
        $this->assertSame('USD', $result->currencyCode);
    }

    public function test_preview_returns_line_items(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkPreviewResult());

        $service = new PricePreviewService($client);
        $result  = $service->preview(new PricePreviewRequest(
            items: [new PricePreviewItem(PaddlePriceId::of('pri_test'), 2)],
        ));

        $this->assertCount(1, $result->items);
        $this->assertSame('pri_test', $result->items[0]->priceId->value);
        $this->assertSame(2, $result->items[0]->quantity);
        $this->assertSame('2000', $result->items[0]->subtotal);
        $this->assertSame('400', $result->items[0]->tax);
        $this->assertSame('2400', $result->items[0]->total);
    }

    public function test_preview_delegates_to_api_client(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())
            ->method('call')
            ->willReturn($this->makeSdkPreviewResult());

        $service = new PricePreviewService($client);
        $service->preview(new PricePreviewRequest(
            items: [new PricePreviewItem(PaddlePriceId::of('pri_test'), 1)],
        ));
    }
}
