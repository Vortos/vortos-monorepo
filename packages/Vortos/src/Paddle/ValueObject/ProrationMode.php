<?php

declare(strict_types=1);

namespace Vortos\Paddle\ValueObject;

enum ProrationMode: string
{
    case ProratedImmediately         = 'prorated_immediately';
    case ProratedNextBillingPeriod   = 'prorated_next_billing_period';
    case FullImmediately             = 'full_immediately';
    case FullNextBillingPeriod       = 'full_next_billing_period';
    case DoNotBill                   = 'do_not_bill';
}
