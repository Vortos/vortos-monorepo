<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Preflight\SecretReference;
use Vortos\Secrets\Service\SecretsPreflight;
use Vortos\Secrets\Tests\Fixtures\InMemorySecretsProvider;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretValue;

final class SecretsPreflightTest extends TestCase
{
    public function test_passes_when_all_required_secrets_present(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('database-password'), SecretValue::fromString('v'));

        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('database-password')),
        ]);

        $report = (new SecretsPreflight())->check($provider, $required);

        self::assertTrue($report->isSatisfied());
        self::assertSame('All required secrets are present.', $report->explain());
    }

    public function test_fails_closed_and_names_every_missing_secret(): void
    {
        $provider = new InMemorySecretsProvider();
        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('missing-one')),
            new SecretReference(SecretKey::fromString('missing-two')),
        ]);

        $report = (new SecretsPreflight())->check($provider, $required);

        self::assertFalse($report->isSatisfied());
        self::assertSame(['missing-one', 'missing-two'], $report->missingKeyNames());
        self::assertStringContainsString('missing-one', $report->explain());
        self::assertStringContainsString('missing-two', $report->explain());
    }

    public function test_optional_missing_secret_does_not_fail_preflight(): void
    {
        $provider = new InMemorySecretsProvider();
        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('optional-secret'), required: false),
        ]);

        $report = (new SecretsPreflight())->check($provider, $required);

        self::assertTrue($report->isSatisfied());
    }

    public function test_does_not_reveal_any_value(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('present-secret'), SecretValue::fromString('super-secret-value'));

        $required = new RequiredSecrets([new SecretReference(SecretKey::fromString('present-secret'))]);
        $report = (new SecretsPreflight())->check($provider, $required);

        self::assertStringNotContainsString('super-secret-value', print_r($report->toArray(), true));
    }
}
