<?php

declare(strict_types=1);

namespace Vortos\Paddle\Tests\Transaction;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Transaction\Adjustment;
use Vortos\Paddle\Transaction\ImmediateAdjustmentService;
use Vortos\Paddle\Transaction\Exception\OverRefundException;
use Vortos\Paddle\Transaction\Operation\AdjustmentItemRequest;
use Vortos\Paddle\Transaction\Operation\CreateRefundRequest;
use Vortos\Paddle\Transaction\Operation\CreateCreditRequest;
use Vortos\Paddle\ValueObject\PaddleAdjustmentId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

final class ImmediateAdjustmentServiceTest extends TestCase
{
    private function makeSdkTransactionWithLineItem(
        string $lineItemId,
        string $lineItemTotal,
        string $txnId = 'txn_test'
    ): \Paddle\SDK\Entities\Transaction {
        return \Paddle\SDK\Entities\Transaction::from([
            'id'                        => $txnId,
            'status'                    => 'billed',
            'customer_id'               => 'ctm_test',
            'address_id'                => null,
            'business_id'               => null,
            'custom_data'               => null,
            'currency_code'             => 'USD',
            'origin'                    => 'api',
            'subscription_id'           => null,
            'invoice_id'                => null,
            'invoice_number'            => null,
            'collection_mode'           => 'automatic',
            'discount_id'               => null,
            'billing_details'           => null,
            'billing_period'            => null,
            'items'                     => [],
            'details'                   => [
                'tax_rates_used' => [],
                'totals'         => [
                    'subtotal'          => '1000',
                    'discount'          => '0',
                    'tax'               => '200',
                    'total'             => $lineItemTotal,
                    'credit'            => '0',
                    'balance'           => $lineItemTotal,
                    'grand_total'       => $lineItemTotal,
                    'fee'               => null,
                    'earnings'          => null,
                    'currency_code'     => 'USD',
                    'credit_to_balance' => '0',
                    'grand_total_tax'   => '200',
                ],
                'line_items' => [
                    [
                        'id'          => $lineItemId,
                        'price_id'    => 'pri_test',
                        'quantity'    => 1,
                        'proration'   => null,
                        'tax_rate'    => '0.2',
                        'unit_totals' => ['subtotal' => '1000', 'discount' => '0', 'tax' => '200', 'total' => $lineItemTotal],
                        'totals'      => ['subtotal' => '1000', 'discount' => '0', 'tax' => '200', 'total' => $lineItemTotal],
                        'product'     => [
                            'id'             => 'pro_test',
                            'name'           => 'Test Product',
                            'tax_category'   => 'standard',
                            'description'    => null,
                            'type'           => 'standard',
                            'image_url'      => null,
                            'status'         => 'active',
                            'custom_data'    => null,
                            'created_at'     => '2024-01-01T00:00:00.000000Z',
                            'updated_at'     => '2024-01-02T00:00:00.000000Z',
                            'import_meta'    => null,
                        ],
                    ],
                ],
            ],
            'payments'                  => [],
            'checkout'                  => null,
            'created_at'                => '2024-01-01T00:00:00.000000Z',
            'updated_at'                => '2024-01-02T00:00:00.000000Z',
            'billed_at'                 => null,
            'address'                   => null,
            'adjustments'               => [],
            'adjustments_totals'        => null,
            'business'                  => null,
            'customer'                  => null,
            'discount'                  => null,
            'available_payment_methods' => [],
            'revised_at'                => null,
        ]);
    }

    private function makeSdkAdjustment(string $id = 'adj_test'): \Paddle\SDK\Entities\Adjustment
    {
        return \Paddle\SDK\Entities\Adjustment::from([
            'id'                     => $id,
            'action'                 => 'refund',
            'transaction_id'         => 'txn_test',
            'subscription_id'        => null,
            'customer_id'            => 'ctm_test',
            'reason'                 => 'Customer request',
            'credit_applied_to_balance' => null,
            'currency_code'          => 'USD',
            'status'                 => 'pending_approval',
            'items'                  => [],
            'totals'                 => [
                'subtotal'     => '500',
                'tax'          => '100',
                'total'        => '600',
                'fee'          => '0',
                'retained_fee' => '0',
                'earnings'     => '500',
                'currency_code' => 'USD',
            ],
            'payout_totals' => null,
            'tax_rates_used' => [],
            'created_at'    => '2024-01-01T00:00:00.000000Z',
            'updated_at'    => '2024-01-02T00:00:00.000000Z',
            'type'          => 'partial',
        ]);
    }

    public function test_create_refund_within_line_item_amount(): void
    {
        $sdkTransaction = $this->makeSdkTransactionWithLineItem('li_abc', '1200');
        $sdkAdjustment  = $this->makeSdkAdjustment('adj_new');

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')
               ->willReturnOnConsecutiveCalls($sdkTransaction, $sdkAdjustment);

        $service = new ImmediateAdjustmentService($client);
        $id      = $service->createRefund(new CreateRefundRequest(
            transactionId: PaddleTransactionId::of('txn_test'),
            reason:        'Customer request',
            items:         [new AdjustmentItemRequest('li_abc', '600')],
        ));

        $this->assertInstanceOf(PaddleAdjustmentId::class, $id);
        $this->assertSame('adj_new', $id->value);
    }

    public function test_create_refund_exceeding_line_item_amount_throws(): void
    {
        $sdkTransaction = $this->makeSdkTransactionWithLineItem('li_abc', '1200');

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($sdkTransaction);

        $service = new ImmediateAdjustmentService($client);

        $this->expectException(OverRefundException::class);
        $this->expectExceptionMessage('li_abc');

        $service->createRefund(new CreateRefundRequest(
            transactionId: PaddleTransactionId::of('txn_test'),
            reason:        'Oops',
            items:         [new AdjustmentItemRequest('li_abc', '9999')],
        ));
    }

    public function test_create_refund_equal_to_line_item_amount_passes(): void
    {
        $sdkTransaction = $this->makeSdkTransactionWithLineItem('li_abc', '1200');
        $sdkAdjustment  = $this->makeSdkAdjustment('adj_full');

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')
               ->willReturnOnConsecutiveCalls($sdkTransaction, $sdkAdjustment);

        $service = new ImmediateAdjustmentService($client);
        $id      = $service->createRefund(new CreateRefundRequest(
            transactionId: PaddleTransactionId::of('txn_test'),
            reason:        'Full refund',
            items:         [new AdjustmentItemRequest('li_abc', '1200')],
        ));

        $this->assertSame('adj_full', $id->value);
    }

    public function test_create_credit_delegates_to_sdk(): void
    {
        $sdkAdjustment = $this->makeSdkAdjustment('adj_credit');

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call')->willReturn($sdkAdjustment);

        $service = new ImmediateAdjustmentService($client);
        $id      = $service->createCredit(new CreateCreditRequest(
            transactionId: PaddleTransactionId::of('txn_test'),
            reason:        'Goodwill credit',
            items:         [new AdjustmentItemRequest('li_abc', '100')],
        ));

        $this->assertSame('adj_credit', $id->value);
    }

    public function test_list_returns_array_of_adjustments(): void
    {
        $collection = new \ArrayIterator([
            $this->makeSdkAdjustment('adj_1'),
            $this->makeSdkAdjustment('adj_2'),
        ]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service     = new ImmediateAdjustmentService($client);
        $adjustments = $service->list();

        $this->assertCount(2, $adjustments);
        $this->assertContainsOnlyInstancesOf(Adjustment::class, $adjustments);
    }
}
