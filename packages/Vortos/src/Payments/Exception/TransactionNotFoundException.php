<?php

declare(strict_types=1);

namespace Vortos\Payments\Exception;

/**
 * The rail has no transaction under this reference.
 *
 * Never treat as "therefore unpaid" without knowing the reference was ever
 * sent: an unknown reference and a lost one look identical from here.
 */
final class TransactionNotFoundException extends PaymentsException
{
    public static function for(string $gatewayId, string $reference): self
    {
        return new self(sprintf('%s has no transaction %s.', $gatewayId, $reference));
    }
}
