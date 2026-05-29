<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\Checkout;

use PHPUnit\Framework\TestCase;
use Vortos\Paddle\Api\PaddleApiClientInterface;
use Vortos\Paddle\Checkout\CheckoutService;
use Vortos\Paddle\Checkout\CheckoutUrl;
use Vortos\Paddle\Checkout\SubscriptionCheckoutRequest;
use Vortos\Paddle\ValueObject\PaddleSubscriptionId;
use Vortos\Paddle\ValueObject\PaddleTransactionId;

final class CheckoutServiceTest extends TestCase
{
    private function makeSdkTransactionWithCheckout(string $id, string $checkoutUrl): \Paddle\SDK\Entities\Transaction
    {
        return \Paddle\SDK\Entities\Transaction::from([
            'id'                        => $id,
            'status'                    => 'ready',
            'customer_id'               => 'ctm_test',
            'address_id'                => null,
            'business_id'               => null,
            'custom_data'               => null,
            'currency_code'             => 'USD',
            'origin'                    => 'api',
            'subscription_id'           => null,
            'invoice_id'                => null,
            'invoice_number'            => null,
            'collection_mode'           => 'manual',
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
                    'total'             => '1200',
                    'credit'            => '0',
                    'balance'           => '1200',
                    'grand_total'       => '1200',
                    'fee'               => null,
                    'earnings'          => null,
                    'currency_code'     => 'USD',
                    'credit_to_balance' => '0',
                    'grand_total_tax'   => '200',
                ],
                'line_items' => [],
            ],
            'payments'                  => [],
            'checkout'                  => ['url' => $checkoutUrl],
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

    private function makeSdkSubscriptionWithManagementUrls(string $id, string $updatePaymentUrl): \Paddle\SDK\Entities\Subscription
    {
        return \Paddle\SDK\Entities\Subscription::from([
            'id'              => $id,
            'status'          => 'active',
            'customer_id'     => 'ctm_test',
            'address_id'      => 'add_test',
            'business_id'     => null,
            'currency_code'   => 'USD',
            'created_at'      => '2024-01-01T00:00:00.000000Z',
            'updated_at'      => '2024-01-02T00:00:00.000000Z',
            'started_at'      => null,
            'first_billed_at' => null,
            'next_billed_at'  => null,
            'paused_at'       => null,
            'canceled_at'     => null,
            'discount'        => null,
            'collection_mode' => 'automatic',
            'billing_details' => null,
            'current_billing_period' => null,
            'billing_cycle'   => ['interval' => 'month', 'frequency' => 1],
            'scheduled_change' => null,
            'management_urls' => [
                'update_payment_method' => $updatePaymentUrl,
                'cancel'                => 'https://paddle.com/cancel',
            ],
            'items'           => [],
            'custom_data'     => null,
            'import_meta'     => null,
            'next_transaction' => null,
            'recurring_transaction_details' => null,
        ]);
    }

    public function test_create_transaction_checkout_returns_url(): void
    {
        $url = 'https://buy.paddle.com/checkout/txn_abc';
        $sdk = $this->makeSdkTransactionWithCheckout('txn_abc', $url);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($sdk);

        $service     = new CheckoutService($client);
        $checkoutUrl = $service->createTransactionCheckout(PaddleTransactionId::of('txn_abc'));

        $this->assertInstanceOf(CheckoutUrl::class, $checkoutUrl);
        $this->assertSame($url, $checkoutUrl->url);
        $this->assertStringContainsString('txn_abc', (string) $checkoutUrl);
    }

    public function test_create_subscription_checkout_returns_update_payment_url(): void
    {
        $url = 'https://paddle.com/subscription/sub_abc/update-payment';
        $sdk = $this->makeSdkSubscriptionWithManagementUrls('sub_abc', $url);

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($sdk);

        $service     = new CheckoutService($client);
        $checkoutUrl = $service->createSubscriptionCheckout(
            new SubscriptionCheckoutRequest(PaddleSubscriptionId::of('sub_abc'))
        );

        $this->assertInstanceOf(CheckoutUrl::class, $checkoutUrl);
        $this->assertSame($url, $checkoutUrl->url);
        $this->assertStringContainsString('sub_abc', (string) $checkoutUrl);
    }

    public function test_transaction_without_checkout_url_throws(): void
    {
        $sdkNoCheckout = \Paddle\SDK\Entities\Transaction::from([
            'id'                        => 'txn_nocheckout',
            'status'                    => 'draft',
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
                    'subtotal'          => '0',
                    'discount'          => '0',
                    'tax'               => '0',
                    'total'             => '0',
                    'credit'            => '0',
                    'balance'           => '0',
                    'currency_code'     => 'USD',
                    'credit_to_balance' => '0',
                    'grand_total_tax'   => '0',
                ],
                'line_items' => [],
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

        $client = $this->createMock(PaddleApiClientInterface::class);
        $client->method('call')->willReturn($sdkNoCheckout);

        $service = new CheckoutService($client);

        $this->expectException(\RuntimeException::class);
        $service->createTransactionCheckout(PaddleTransactionId::of('txn_nocheckout'));
    }
}
