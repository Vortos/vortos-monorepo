<?php

declare(strict_types=1);

namespace Vortos\Payments\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Payments\Enum\CheckoutMode;
use Vortos\Payments\ValueObject\RailCapabilities;

final class RailCapabilitiesTest extends TestCase
{
    public function testAMerchantOfRecordCannotDisclaimTaxOrChargebacks(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RailCapabilities(
            isMerchantOfRecord:         true,
            remitsTax:                  false,
            handlesChargebacks:         true,
            reportsPerTransactionFee:   true,
            supportsRefunds:            true,
            supportedCurrencies:        ['USD'],
            settlementCurrency:         'USD',
            conversionFallbackCurrency: 'USD',
            checkoutMode:               CheckoutMode::Overlay,
        );
    }

    public function testSettlementCurrencyMustBeBillable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RailCapabilities(
            isMerchantOfRecord:         false,
            remitsTax:                  false,
            handlesChargebacks:         false,
            reportsPerTransactionFee:   false,
            supportsRefunds:            true,
            supportedCurrencies:        ['LKR'],
            settlementCurrency:         'USD',
            conversionFallbackCurrency: null,
            checkoutMode:               CheckoutMode::Redirect,
        );
    }

    /**
     * The LKR fix, expressed as a property of the rail: PayHere bills LKR, so
     * an LKR price needs no conversion and the organiser is credited what they
     * published.
     */
    public function testAGatewayRailWithoutAFallbackRefusesToConvert(): void
    {
        $payhere = $this->payhereLike();

        self::assertFalse($payhere->requiresConversionFor('LKR'));
        self::assertSame('LKR', $payhere->chargeCurrencyFor('LKR'));

        // Nothing to fall back to means nothing gets converted behind our back.
        self::assertTrue($payhere->requiresConversionFor('JPY'));
        self::assertNull($payhere->chargeCurrencyFor('JPY'));
    }

    public function testAMerchantOfRecordRailConvertsToItsFallback(): void
    {
        $paddle = $this->paddleLike();

        self::assertTrue($paddle->requiresConversionFor('LKR'));
        self::assertSame('USD', $paddle->chargeCurrencyFor('LKR'));
        self::assertFalse($paddle->requiresConversionFor('USD'));
        self::assertSame('USD', $paddle->chargeCurrencyFor('usd'));
    }

    public function testDuplicateAndMalformedCurrenciesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RailCapabilities(
            isMerchantOfRecord:         false,
            remitsTax:                  false,
            handlesChargebacks:         false,
            reportsPerTransactionFee:   false,
            supportsRefunds:            false,
            supportedCurrencies:        ['USD', 'USD'],
            settlementCurrency:         'USD',
            conversionFallbackCurrency: null,
            checkoutMode:               CheckoutMode::Redirect,
        );
    }

    private function paddleLike(): RailCapabilities
    {
        return new RailCapabilities(
            isMerchantOfRecord:         true,
            remitsTax:                  true,
            handlesChargebacks:         true,
            reportsPerTransactionFee:   true,
            supportsRefunds:            true,
            supportedCurrencies:        ['USD', 'EUR', 'GBP'],
            settlementCurrency:         'USD',
            conversionFallbackCurrency: 'USD',
            checkoutMode:               CheckoutMode::Overlay,
        );
    }

    private function payhereLike(): RailCapabilities
    {
        return new RailCapabilities(
            isMerchantOfRecord:         false,
            remitsTax:                  false,
            handlesChargebacks:         false,
            reportsPerTransactionFee:   false,
            supportsRefunds:            true,
            supportedCurrencies:        ['LKR', 'USD'],
            settlementCurrency:         'LKR',
            conversionFallbackCurrency: null,
            checkoutMode:               CheckoutMode::Redirect,
        );
    }
}
