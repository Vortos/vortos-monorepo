<?php

declare(strict_types=1);

namespace Vortos\Secrets\Crypto;

use InvalidArgumentException;

/**
 * Minimal, dependency-free Bech32 (BIP-173) decoder — the encoding the `age` tool uses for its
 * keys (`age1…` recipients, `AGE-SECRET-KEY-1…` identities). Only decoding is implemented (the
 * framework never mints age keys); full HRP + checksum validation is enforced and any deviation
 * fails closed. Bech32m is deliberately rejected — age uses plain Bech32.
 */
final class Bech32
{
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const BECH32_CONST = 1;

    /** @var list<int> */
    private const GENERATOR = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

    /**
     * Decode a Bech32 string into its human-readable part and the raw decoded bytes.
     *
     * @return array{hrp: string, bytes: string}
     */
    public static function decode(string $bech): array
    {
        if (strlen($bech) < 8 || strlen($bech) > 2048) {
            throw new InvalidArgumentException('Bech32 string has an invalid length.');
        }

        $lower = strtolower($bech);
        $upper = strtoupper($bech);
        if ($bech !== $lower && $bech !== $upper) {
            throw new InvalidArgumentException('Bech32 string must not mix upper- and lower-case.');
        }
        $bech = $lower;

        $sepPos = strrpos($bech, '1');
        if ($sepPos === false || $sepPos < 1 || $sepPos + 7 > strlen($bech)) {
            throw new InvalidArgumentException('Bech32 string has no valid separator.');
        }

        $hrp = substr($bech, 0, $sepPos);
        for ($i = 0, $n = strlen($hrp); $i < $n; $i++) {
            $ord = ord($hrp[$i]);
            if ($ord < 33 || $ord > 126) {
                throw new InvalidArgumentException('Bech32 human-readable part contains an invalid character.');
            }
        }

        $dataPart = substr($bech, $sepPos + 1);
        $data = [];
        for ($i = 0, $n = strlen($dataPart); $i < $n; $i++) {
            $pos = strpos(self::CHARSET, $dataPart[$i]);
            if ($pos === false) {
                throw new InvalidArgumentException('Bech32 data part contains an invalid character.');
            }
            $data[] = $pos;
        }

        if (self::polymod([...self::hrpExpand($hrp), ...$data]) !== self::BECH32_CONST) {
            throw new InvalidArgumentException('Bech32 checksum is invalid.');
        }

        // Drop the 6-symbol checksum, then regroup the 5-bit values into bytes.
        $payload = array_slice($data, 0, count($data) - 6);
        $bytes = self::convertBits($payload, 5, 8, false);

        return ['hrp' => $hrp, 'bytes' => $bytes];
    }

    /**
     * @param list<int> $values
     */
    private static function polymod(array $values): int
    {
        $chk = 1;
        foreach ($values as $value) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $value;
            for ($i = 0; $i < 5; $i++) {
                if (($top >> $i) & 1) {
                    $chk ^= self::GENERATOR[$i];
                }
            }
        }

        return $chk;
    }

    /**
     * @return list<int>
     */
    private static function hrpExpand(string $hrp): array
    {
        $high = [];
        $low = [];
        for ($i = 0, $n = strlen($hrp); $i < $n; $i++) {
            $ord = ord($hrp[$i]);
            $high[] = $ord >> 5;
            $low[] = $ord & 31;
        }

        return [...$high, 0, ...$low];
    }

    /**
     * @param list<int> $data
     */
    private static function convertBits(array $data, int $fromBits, int $toBits, bool $pad): string
    {
        $acc = 0;
        $bits = 0;
        $out = '';
        $maxv = (1 << $toBits) - 1;

        foreach ($data as $value) {
            if ($value < 0 || ($value >> $fromBits) !== 0) {
                throw new InvalidArgumentException('Bech32 value out of range during bit conversion.');
            }
            $acc = ($acc << $fromBits) | $value;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $out .= chr(($acc >> $bits) & $maxv);
            }
        }

        if ($pad) {
            if ($bits > 0) {
                $out .= chr(($acc << ($toBits - $bits)) & $maxv);
            }
        } elseif ($bits >= $fromBits || (($acc << ($toBits - $bits)) & $maxv) !== 0) {
            throw new InvalidArgumentException('Bech32 has excess or non-zero padding bits.');
        }

        return $out;
    }
}
