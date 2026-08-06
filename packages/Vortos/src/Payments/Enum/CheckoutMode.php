<?php

declare(strict_types=1);

namespace Vortos\Payments\Enum;

/**
 * How the payer reaches the rail's payment form.
 *
 * This is a capability of the rail, not a preference of the caller: a rail
 * that only offers a hosted page cannot be asked for an overlay. It is
 * declared on RailCapabilities and echoed on every CheckoutInstruction so a
 * browser client can branch on one discriminator instead of sniffing which
 * fields happen to be present.
 */
enum CheckoutMode: string
{
    /**
     * The rail's own script paints a modal over our page and the payer never
     * navigates away. Cheap UX, but it puts a third-party script on the money
     * path and inherits every popup and CSP failure mode that comes with it.
     */
    case Overlay = 'overlay';

    /**
     * The payer is sent to the rail's hosted page and comes back to a return
     * URL. Nothing third-party runs on our origin.
     *
     * The return is a *navigation*, never evidence: settlement is only ever
     * believed from a verified webhook. A payer who closes the tab at the
     * right moment must still end up settled, and a payer who forges a return
     * URL must not.
     */
    case Redirect = 'redirect';
}
