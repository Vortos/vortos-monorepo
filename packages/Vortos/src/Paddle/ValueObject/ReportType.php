<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

enum ReportType: string
{
    case Adjustments            = 'adjustments';
    case AdjustmentLineItems    = 'adjustment_line_items';
    case Discounts              = 'discounts';
    case ProductsPrices         = 'products_prices';
    case Transactions           = 'transactions';
    case TransactionLineItems   = 'transaction_line_items';
    case Balance                = 'balance';
    case PayoutReconciliation   = 'payout_reconciliation';
}
