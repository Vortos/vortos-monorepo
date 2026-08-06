<?php

declare(strict_types=1);

namespace Vortos\Paddle\Gateway;

/**
 * The currencies Paddle can bill a card in directly.
 *
 * ── Why it lives in this package ──────────────────────────────────────────
 * It used to be a global "chargeable currency" list in the application, which
 * made every rail the platform ever adds inherit Paddle's limits. That is
 * exactly how an organiser pricing in LKR ended up billed in converted USD
 * while a rail that bills LKR natively sat unused: the list said LKR was not
 * chargeable, and the list was speaking for a rail it had never met.
 *
 * Which currencies a rail can bill is a fact about that rail, so it is
 * declared by that rail — here, and nowhere else.
 *
 * ── Why an enum and not config ────────────────────────────────────────────
 * Whether a currency is chargeable decides whether a payment converts, and a
 * conversion is the difference between an organiser being credited what they
 * published and being credited something else. That belongs in the type
 * system, where widening it is a reviewed code change, not in a file someone
 * can edit at 2am.
 *
 * The list tracks Paddle's published support. When Paddle gains a currency,
 * add the case — the conversion fallback means the only cost of being out of
 * date is an unnecessary conversion, never a failed payment.
 */
enum PaddleChargeableCurrency: string
{
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case JPY = 'JPY';
    case AUD = 'AUD';
    case CAD = 'CAD';
    case CHF = 'CHF';
    case HKD = 'HKD';
    case SGD = 'SGD';
    case NZD = 'NZD';
    case SEK = 'SEK';
    case NOK = 'NOK';
    case DKK = 'DKK';
    case PLN = 'PLN';
    case CZK = 'CZK';
    case HUF = 'HUF';
    case INR = 'INR';
    case CNY = 'CNY';
    case KRW = 'KRW';
    case TWD = 'TWD';
    case THB = 'THB';
    case ZAR = 'ZAR';
    case BRL = 'BRL';
    case MXN = 'MXN';
    case ARS = 'ARS';
    case ILS = 'ILS';
    case RUB = 'RUB';
    case TRY = 'TRY';
    case UAH = 'UAH';

    /**
     * Where a price Paddle cannot bill is converted to.
     *
     * USD rather than a regional major, because FX snapshots are quoted per
     * USD — converting to it is one multiplication instead of a cross-rate with
     * two rounding sites.
     */
    public const FALLBACK = self::USD;

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
