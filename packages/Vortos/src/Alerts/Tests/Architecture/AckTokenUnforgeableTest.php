<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Architecture;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Escalation\AckTokenException;
use Vortos\Alerts\Escalation\AckTokenSigner;

/** Property-style: random tampering of any token field is rejected. */
final class AckTokenUnforgeableTest extends TestCase
{
    public function test_random_byte_flips_are_always_rejected(): void
    {
        $signer = new AckTokenSigner('property-test-key');
        $now = new DateTimeImmutable();
        $token = $signer->issue('fp-property', 3, $now, 900);

        $rejections = 0;
        $attempts = 200;

        for ($i = 0; $i < $attempts; $i++) {
            $tampered = $this->flipRandomByte($token, $i);
            if ($tampered === $token) {
                $rejections++; // flip happened to be a no-op char; count as trivially safe
                continue;
            }

            try {
                $signer->verify($tampered, $now);
                // If verify() did not throw, the only acceptable reason is the flip
                // produced the exact same token, which we already excluded above.
                self::fail('Tampered ack token was accepted: ' . $tampered);
            } catch (AckTokenException) {
                $rejections++;
            }
        }

        self::assertSame($attempts, $rejections);
    }

    private function flipRandomByte(string $token, int $seed): string
    {
        mt_srand($seed);
        $position = mt_rand(0, strlen($token) - 1);
        $char = $token[$position];
        $flipped = chr((ord($char) + 1) % 256);

        return substr_replace($token, $flipped, $position, 1);
    }
}
