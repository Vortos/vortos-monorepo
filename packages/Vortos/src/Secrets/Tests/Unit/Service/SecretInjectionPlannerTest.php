<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Exception\SecretNotFoundException;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Preflight\SecretReference;
use Vortos\Secrets\Service\SecretInjectionPlanner;
use Vortos\Secrets\Tests\Fixtures\InMemorySecretsProvider;
use Vortos\Secrets\Value\SecretKey;
use Vortos\Secrets\Value\SecretValue;

final class SecretInjectionPlannerTest extends TestCase
{
    public function test_plan_materializes_to_env_var_map(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('database-password'), SecretValue::fromString('s3cr3t'));

        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('database-password')),
        ]);

        $plan = (new SecretInjectionPlanner())->plan($provider, $required);

        self::assertSame(['DATABASE_PASSWORD'], $plan->envVars());
        self::assertSame(['DATABASE_PASSWORD' => 's3cr3t'], $plan->materialize());
    }

    public function test_missing_required_reference_fails_closed(): void
    {
        $provider = new InMemorySecretsProvider();
        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('missing-secret'), required: true),
        ]);

        $this->expectException(SecretNotFoundException::class);
        (new SecretInjectionPlanner())->plan($provider, $required);
    }

    public function test_missing_optional_reference_is_silently_omitted(): void
    {
        $provider = new InMemorySecretsProvider();
        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('missing-optional'), required: false),
        ]);

        $plan = (new SecretInjectionPlanner())->plan($provider, $required);

        self::assertSame([], $plan->entries);
    }

    public function test_plan_preserves_declared_order(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('first'), SecretValue::fromString('1'));
        $provider->put(SecretKey::fromString('second'), SecretValue::fromString('2'));

        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('second')),
            new SecretReference(SecretKey::fromString('first')),
        ]);

        $plan = (new SecretInjectionPlanner())->plan($provider, $required);

        self::assertSame(['SECOND', 'FIRST'], $plan->envVars());
    }

    public function test_does_not_reveal_during_planning(): void
    {
        $provider = new InMemorySecretsProvider();
        $provider->put(SecretKey::fromString('lazy-secret'), SecretValue::fromString('value'));

        $required = new RequiredSecrets([new SecretReference(SecretKey::fromString('lazy-secret'))]);
        $plan = (new SecretInjectionPlanner())->plan($provider, $required);

        self::assertSame('***', (string) $plan->entries[0]->value);
    }
}
