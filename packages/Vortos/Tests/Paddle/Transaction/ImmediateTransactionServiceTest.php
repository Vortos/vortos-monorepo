<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Transaction;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Transaction\ImmediateTransactionService;
use Vortos\Paddle\Transaction\Transaction;
use Vortos\Paddle\Transaction\TransactionPreviewResult;
use Vortos\Paddle\Transaction\Operation\CreateTransactionRequest;
use Vortos\Paddle\Transaction\Operation\TransactionItemRequest;
use Vortos\Paddle\Transaction\Operation\UpdateTransactionRequest;
use Vortos\Paddle\ValueObject\PaddleCustomerId;
use Vortos\Paddle\ValueObject\PaddlePriceId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

final class ImmediateTransactionServiceTest extends TestCase
{
    private function makeSdkTransaction(string $id = 'txn_test_123', string $status = 'draft'): \Paddle\SDK\Entities\Transaction
    {
        return \Paddle\SDK\Entities\Transaction::from([
            'id'                       => $id,
            'status'                   => $status,
            'customer_id'              => 'ctm_test',
            'address_id'               => null,
            'business_id'              => null,
            'custom_data'              => null,
            'currency_code'            => 'USD',
            'origin'                   => 'api',
            'subscription_id'          => null,
            'invoice_id'               => null,
            'invoice_number'           => null,
            'collection_mode'          => 'automatic',
            'discount_id'              => null,
            'billing_details'          => null,
            'billing_period'           => null,
            'items'                    => [],
            'details'                  => [
                'tax_rates_used' => [],
                'totals'         => [
                    'subtotal'        => '1000',
                    'discount'        => '0',
                    'tax'             => '200',
                    'total'           => '1200',
                    'credit'          => '0',
                    'balance'         => '1200',
                    'grand_total'     => '1200',
                    'fee'             => null,
                    'earnings'        => null,
                    'currency_code'   => 'USD',
                    'credit_to_balance' => '0',
                    'grand_total_tax' => '200',
                ],
                'line_items' => [],
            ],
            'payments'                 => [],
            'checkout'                 => null,
            'created_at'               => '2024-01-01T00:00:00.000000Z',
            'updated_at'               => '2024-01-02T00:00:00.000000Z',
            'billed_at'                => null,
            'address'                  => null,
            'adjustments'              => [],
            'adjustments_totals'       => null,
            'business'                 => null,
            'customer'                 => null,
            'discount'                 => null,
            'available_payment_methods' => [],
            'revised_at'               => null,
        ]);
    }

    public function test_create_returns_transaction_id(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkTransaction('txn_abc'));

        $service = new ImmediateTransactionService($client);
        $id      = $service->create(new CreateTransactionRequest(
            customerId: PaddleCustomerId::of('ctm_123'),
            items:      [new TransactionItemRequest(PaddlePriceId::of('pri_123'), 1)],
        ));

        $this->assertInstanceOf(PaddleTransactionId::class, $id);
        $this->assertSame('txn_abc', $id->value);
    }

    public function test_get_returns_mapped_transaction(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($this->makeSdkTransaction('txn_xyz', 'billed'));

        $service     = new ImmediateTransactionService($client);
        $transaction = $service->get(PaddleTransactionId::of('txn_xyz'));

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertSame('txn_xyz', $transaction->id->value);
        $this->assertSame('USD', $transaction->currencyCode);
        $this->assertSame('1200', $transaction->total);
        $this->assertSame(\Vortos\Paddle\ValueObject\TransactionStatus::Billed, $transaction->status);
    }

    public function test_update_delegates_to_sdk(): void
    {
        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->expects($this->once())->method('call');

        $service = new ImmediateTransactionService($client);
        $service->update(PaddleTransactionId::of('txn_123'), new UpdateTransactionRequest());
    }

    public function test_get_invoice_pdf_url_returns_string(): void
    {
        $sdkData = \Paddle\SDK\Entities\TransactionData::from(['url' => 'https://example.com/invoice.pdf']);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($sdkData);

        $service = new ImmediateTransactionService($client);
        $url     = $service->getInvoicePdfUrl(PaddleTransactionId::of('txn_123'));

        $this->assertSame('https://example.com/invoice.pdf', $url);
    }

    public function test_list_returns_array_of_transactions(): void
    {
        $collection = new \ArrayIterator([
            $this->makeSdkTransaction('txn_1'),
            $this->makeSdkTransaction('txn_2'),
        ]);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($collection);

        $service      = new ImmediateTransactionService($client);
        $transactions = $service->list();

        $this->assertCount(2, $transactions);
        $this->assertContainsOnlyInstancesOf(Transaction::class, $transactions);
    }
}
