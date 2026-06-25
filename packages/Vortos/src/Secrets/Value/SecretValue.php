<?php

declare(strict_types=1);

namespace Vortos\Secrets\Value;

use JsonSerializable;
use Vortos\Secrets\Exception\SecretAlreadyWipedException;
use WeakMap;

/**
 * A secret plaintext, redacted by construction.
 *
 * This is the single object every secret value flows through, from the moment it
 * leaves the cipher to the moment it is injected into a process environment.
 * Every accidental leak path is closed by construction:
 *
 *  - {@see __toString()}, {@see __debugInfo()}, {@see jsonSerialize()} all render
 *    the literal string `***`.
 *  - **The plaintext is never an instance property.** It lives in a private
 *    static {@see WeakMap} keyed by object identity. This is deliberate: PHP's
 *    `var_export()` and `print_r()` both bypass `__debugInfo()` and serialize an
 *    object's actual instance properties via engine-level reflection — a plaintext
 *    held as a normal `private string $plaintext` property WOULD leak through
 *    those two functions despite every magic method being overridden. Keeping the
 *    value out-of-band means `var_export($secret)` / `print_r($secret)` show only
 *    the (redacted) declared properties — there is nothing to leak.
 *  - {@see reveal()} is the ONLY method that returns the plaintext.
 *  - `final`: no subclass can widen access.
 *  - {@see wipe()} zeroizes the out-of-band buffer (where the runtime allows) and
 *    permanently disables {@see reveal()} — fail-closed, never a silent empty
 *    string.
 *  - {@see __serialize()} throws: a `SecretValue` must never be serialized (it
 *    would either leak the plaintext into the serialized form, or — since the
 *    plaintext lives in a static map keyed by identity — silently produce a
 *    broken value on unserialize). Both are unacceptable; fail loudly instead.
 */
final class SecretValue implements JsonSerializable
{
    private const REDACTED = '***';

    /** @var WeakMap<self, string> */
    private static WeakMap $vault;

    private bool $wiped = false;

    private function __construct()
    {
        self::$vault ??= new WeakMap();
    }

    public static function fromString(string $plaintext): self
    {
        $secret = new self();
        self::$vault[$secret] = $plaintext;

        return $secret;
    }

    /**
     * The only method that returns the plaintext. Every caller of this method is
     * a deliberate, auditable boundary crossing.
     */
    public function reveal(): string
    {
        if ($this->wiped) {
            throw SecretAlreadyWipedException::create();
        }

        return self::$vault[$this];
    }

    public function equals(self $other): bool
    {
        if ($this->wiped || $other->wiped) {
            throw SecretAlreadyWipedException::create();
        }

        return hash_equals(self::$vault[$this], self::$vault[$other]);
    }

    /**
     * Zeroizes the out-of-band buffer where the runtime allows and permanently
     * disables {@see reveal()}. Idempotent.
     */
    public function wipe(): void
    {
        if ($this->wiped) {
            return;
        }

        if (isset(self::$vault[$this])) {
            $buffer = self::$vault[$this];
            if (function_exists('sodium_memzero')) {
                sodium_memzero($buffer);
            }
            unset(self::$vault[$this]);
        }

        $this->wiped = true;
    }

    public function isWiped(): bool
    {
        return $this->wiped;
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('SecretValue must never be serialized.');
    }
}
