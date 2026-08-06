<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

/**
 * Who is paying, in the shape rails ask for it.
 *
 * ── Why the address fields are here at all ────────────────────────────────
 * Merchant-of-record rails need a country to compute the payer's tax. Gateway
 * rails in some markets — PayHere among them — reject a checkout outright if
 * the address block is incomplete. Neither is optional in practice, so both
 * live on one type and each adapter takes the subset it needs.
 *
 * `email` is the only universally mandatory field, so it is the only one this
 * type insists on. A rail that needs more says so at build time, by name, in
 * its own exception — which is a far better failure than a helpful default of
 * "N/A" quietly reaching a tax calculation.
 */
final readonly class PayerDetails
{
    public function __construct(
        public string  $email,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phone = null,
        public ?string $addressLine1 = null,
        public ?string $city = null,
        /** ISO 3166-1 alpha-2, upper-case. */
        public ?string $countryCode = null,
    ) {
        if (trim($email) === '') {
            throw new \InvalidArgumentException('A payer needs an email; every rail requires one and every receipt is sent to it.');
        }

        if ($countryCode !== null && preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Country "%s" is not an ISO 3166-1 alpha-2 code.',
                $countryCode,
            ));
        }
    }

    public function fullName(): string
    {
        return trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
    }

    /** @return list<string> Names of the fields that are absent. */
    public function missing(string ...$fields): array
    {
        $absent = [];

        foreach ($fields as $field) {
            $value = property_exists($this, $field) ? $this->{$field} : null;

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $absent[] = $field;
            }
        }

        return $absent;
    }
}
