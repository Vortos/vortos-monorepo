<?php

declare(strict_types=1);

namespace Vortos\Payments\Exception;

/**
 * Base for everything this package throws.
 *
 * Callers on the money path are expected to catch the specific subclasses —
 * a currency a rail cannot bill and a rail that is simply down demand opposite
 * responses, and collapsing them into one `catch` is how a payer gets told to
 * "try again later" about a condition that will never resolve.
 */
abstract class PaymentsException extends \RuntimeException
{
}
