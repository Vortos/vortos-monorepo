<?php

declare(strict_types=1);

namespace Vortos\Payments\ValueObject;

use Vortos\Payments\Enum\CheckoutMode;

/**
 * What a browser must do to put the payer in front of the rail's payment form.
 *
 * Serialises to a discriminated union on `mode`, so a client branches on one
 * field instead of guessing from which keys happen to be present. Adding a
 * third mode later adds a case to that switch and breaks nothing that exists —
 * which is the whole reason this is a typed instruction rather than a bag of
 * rail-specific keys leaking out of the API.
 */
final readonly class CheckoutInstruction
{
    /**
     * @param array<string, string> $fields Redirect mode: the exact form body, signed by the rail's rules.
     */
    private function __construct(
        public CheckoutMode $mode,
        /** Overlay mode: the rail-side id the rail's script opens. */
        public ?string      $reference = null,
        /** Redirect mode: where the form POSTs. */
        public ?string      $actionUrl = null,
        public array        $fields = [],
    ) {}

    public static function overlay(string $reference): self
    {
        if (trim($reference) === '') {
            throw new \InvalidArgumentException('An overlay checkout needs the reference its script opens.');
        }

        return new self(CheckoutMode::Overlay, reference: $reference);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function redirect(string $actionUrl, array $fields): self
    {
        // A relative or http:// action URL would post signed payment fields in
        // clear text, or to our own origin. Neither is recoverable once a payer
        // has hit submit, so it is refused here rather than reviewed later.
        if (!str_starts_with($actionUrl, 'https://')) {
            throw new \InvalidArgumentException(sprintf(
                'A redirect checkout must post to an absolute https:// URL, got "%s".',
                $actionUrl,
            ));
        }

        if ($fields === []) {
            throw new \InvalidArgumentException('A redirect checkout needs the fields to post.');
        }

        // Field *types* are enforced statically — a form body has no type but
        // string, and casting one late is how an amount loses a decimal. What
        // static analysis cannot see is an empty name, which serialises into a
        // body the rail parses as a field it was never sent and signs
        // differently than we did.
        foreach ($fields as $name => $value) {
            if (trim($name) === '') {
                throw new \InvalidArgumentException('A redirect field cannot have an empty name.');
            }

            unset($value);
        }

        return new self(CheckoutMode::Redirect, actionUrl: $actionUrl, fields: $fields);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return match ($this->mode) {
            CheckoutMode::Overlay  => [
                'mode'      => $this->mode->value,
                'reference' => $this->reference,
            ],
            CheckoutMode::Redirect => [
                'mode'       => $this->mode->value,
                'action_url' => $this->actionUrl,
                'fields'     => $this->fields,
            ],
        };
    }
}
