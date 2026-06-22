<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Explain;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Explain\FlagOverrideService;

final class FlagOverrideServiceTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long!';

    // ── Master switch ──

    public function test_disabled_service_ignores_all_tokens(): void
    {
        $service = new FlagOverrideService(enabled: false, secret: self::SECRET);

        $token  = $this->createService()->createToken('my-flag');
        $result = $service->applyFromToken($token, 'development');

        $this->assertFalse($result);
        $this->assertFalse($service->hasOverride('my-flag'));
    }

    public function test_empty_secret_disables_service(): void
    {
        $service = new FlagOverrideService(enabled: true, secret: '');

        $this->assertFalse($service->isEnabled());
    }

    // ── Environment guard ──

    public function test_production_environment_is_blocked_by_default(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag');

        $this->assertFalse($service->applyFromToken($token, 'production'));
        $this->assertFalse($service->hasOverride('my-flag'));
    }

    public function test_development_environment_is_allowed(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag');

        $this->assertTrue($service->applyFromToken($token, 'development'));
        $this->assertTrue($service->hasOverride('my-flag'));
    }

    public function test_staging_environment_is_allowed(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag');

        $this->assertTrue($service->applyFromToken($token, 'staging'));
    }

    public function test_custom_allowed_environments(): void
    {
        $service = new FlagOverrideService(
            enabled: true,
            secret: self::SECRET,
            allowedEnvironments: ['qa'],
        );
        $token = $service->createToken('my-flag');

        $this->assertTrue($service->applyFromToken($token, 'qa'));
        $this->assertFalse($service->applyFromToken($token, 'development'));
    }

    // ── Token validation ──

    public function test_valid_token_applies_boolean_override(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag', true);

        $this->assertTrue($service->applyFromToken($token, 'development'));
        $this->assertTrue($service->getOverride('my-flag')->asBool());
    }

    public function test_valid_token_applies_string_override(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag', 'variant-b');

        $service->applyFromToken($token, 'development');

        $override = $service->getOverride('my-flag');
        $this->assertNotNull($override);
        $this->assertSame('variant-b', $override->asString());
    }

    public function test_valid_token_applies_numeric_override(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag', 42);

        $service->applyFromToken($token, 'development');

        $this->assertSame(42.0, $service->getOverride('my-flag')->asNumber());
    }

    public function test_valid_token_applies_json_override(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag', ['key' => 'value']);

        $service->applyFromToken($token, 'development');

        $this->assertSame(['key' => 'value'], $service->getOverride('my-flag')->asJson());
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag');

        // Tamper with the last character of the signature
        $tampered = substr($token, 0, -1) . ($token[-1] === 'a' ? 'b' : 'a');

        $this->assertFalse($service->applyFromToken($tampered, 'development'));
        $this->assertFalse($service->hasOverride('my-flag'));
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $service1 = $this->createService();
        $token    = $service1->createToken('my-flag');

        $service2 = new FlagOverrideService(enabled: true, secret: 'different-secret-that-is-long-enough!!');
        $this->assertFalse($service2->applyFromToken($token, 'development'));
    }

    public function test_malformed_token_format_is_rejected(): void
    {
        $service = $this->createService();

        $this->assertFalse($service->applyFromToken('no-dot-separator', 'development'));
        $this->assertFalse($service->applyFromToken('', 'development'));
        $this->assertFalse($service->applyFromToken('.', 'development'));
    }

    public function test_invalid_base64_is_rejected(): void
    {
        $service = $this->createService();

        $this->assertFalse($service->applyFromToken('not-base64!!!.abc123', 'development'));
    }

    public function test_expired_token_is_rejected(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag', true, -1); // already expired

        $this->assertFalse($service->applyFromToken($token, 'development'));
    }

    public function test_token_without_flag_field_is_rejected(): void
    {
        $service = $this->createService();

        $payload = base64_encode(json_encode(['value' => true]));
        $sig     = hash_hmac('sha256', $payload, self::SECRET);
        $token   = $payload . '.' . $sig;

        $this->assertFalse($service->applyFromToken($token, 'development'));
    }

    // ── Audit log ──

    public function test_audit_log_records_applied_overrides(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag', true);

        $service->applyFromToken($token, 'development', 'actor-1');

        $log = $service->auditLog();
        $this->assertCount(1, $log);
        $this->assertSame('my-flag', $log[0]['flag']);
        $this->assertTrue($log[0]['value']);
        $this->assertSame('actor-1', $log[0]['actor']);
        $this->assertNotEmpty($log[0]['timestamp']);
    }

    public function test_rejected_tokens_do_not_appear_in_audit_log(): void
    {
        $service = $this->createService();

        $service->applyFromToken('invalid', 'development');

        $this->assertEmpty($service->auditLog());
    }

    // ── Reset ──

    public function test_reset_clears_overrides_and_audit_log(): void
    {
        $service = $this->createService();
        $token   = $service->createToken('my-flag');

        $service->applyFromToken($token, 'development');
        $this->assertTrue($service->hasOverride('my-flag'));

        $service->reset();

        $this->assertFalse($service->hasOverride('my-flag'));
        $this->assertEmpty($service->auditLog());
    }

    // ── Non-existent flag override ──

    public function test_get_override_returns_null_for_unknown_flag(): void
    {
        $service = $this->createService();

        $this->assertNull($service->getOverride('nonexistent'));
        $this->assertFalse($service->hasOverride('nonexistent'));
    }

    // ── Multiple overrides ──

    public function test_multiple_tokens_applied_in_sequence(): void
    {
        $service = $this->createService();

        $token1 = $service->createToken('flag-a', true);
        $token2 = $service->createToken('flag-b', 'variant-x');

        $service->applyFromToken($token1, 'development');
        $service->applyFromToken($token2, 'development');

        $this->assertTrue($service->hasOverride('flag-a'));
        $this->assertTrue($service->hasOverride('flag-b'));
        $this->assertCount(2, $service->auditLog());
    }

    public function test_later_override_for_same_flag_wins(): void
    {
        $service = $this->createService();

        $token1 = $service->createToken('my-flag', true);
        $token2 = $service->createToken('my-flag', false);

        $service->applyFromToken($token1, 'development');
        $service->applyFromToken($token2, 'development');

        $this->assertFalse($service->getOverride('my-flag')->asBool());
    }

    private function createService(): FlagOverrideService
    {
        return new FlagOverrideService(enabled: true, secret: self::SECRET);
    }
}
