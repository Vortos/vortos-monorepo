<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Observability\Sink\MessageScrubber;

final class MessageScrubberTest extends TestCase
{
    private MessageScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new MessageScrubber();
    }

    #[DataProvider('leaks')]
    public function test_redacts_known_leaks(string $input, string $mustNotContain): void
    {
        $out = $this->scrubber->scrub($input);

        self::assertStringNotContainsString($mustNotContain, $out);
        self::assertStringContainsString('[redacted]', $out);
    }

    /** @return array<string, array{string, string}> */
    public static function leaks(): array
    {
        return [
            'email' => ['Contact alice@example.com about it', 'alice@example.com'],
            'jwt' => ['token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc123def456', 'eyJhbGciOiJIUzI1NiJ9'],
            'bearer' => ['Authorization: Bearer sk_live_abcdef0123456789', 'sk_live_abcdef0123456789'],
            'password-kv' => ['login failed password=hunter2supersecret', 'hunter2supersecret'],
            'api-key-kv' => ['api_key: AKIA1234567890ABCDEF', 'AKIA1234567890ABCDEF'],
            'card' => ['card 4111 1111 1111 1111 declined', '4111 1111 1111 1111'],
            'long-token' => ['session ABCDEFGHIJKLMNOPQRSTUVWXYZ012345', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345'],
        ];
    }

    public function test_preserves_safe_text(): void
    {
        $safe = 'Order 42 failed in module Billing at step 3';

        self::assertSame($safe, $this->scrubber->scrub($safe));
    }

    public function test_scrub_context_redacts_secret_keys(): void
    {
        $out = $this->scrubber->scrubContext([
            'authorization' => 'Bearer xyz',
            'password' => 'whatever',
            'order_id' => 42,
            'note' => 'fine',
        ]);

        self::assertSame('[redacted]', $out['authorization']);
        self::assertSame('[redacted]', $out['password']);
        self::assertSame(42, $out['order_id']);
        self::assertSame('fine', $out['note']);
    }

    public function test_scrub_context_summarizes_non_scalar(): void
    {
        $out = $this->scrubber->scrubContext(['obj' => new \stdClass()]);

        self::assertSame('<stdClass>', $out['obj']);
    }

    public function test_scrub_context_scrubs_scalar_string_values(): void
    {
        $out = $this->scrubber->scrubContext(['detail' => 'mailto bob@example.com now']);

        self::assertStringNotContainsString('bob@example.com', (string) $out['detail']);
    }
}
