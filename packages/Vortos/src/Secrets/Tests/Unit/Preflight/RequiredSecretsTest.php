<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Preflight\RequiredSecrets;
use Vortos\Secrets\Preflight\SecretReference;
use Vortos\Secrets\Value\SecretKey;

final class RequiredSecretsTest extends TestCase
{
    public function test_required_keys_excludes_optional(): void
    {
        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('a'), required: true),
            new SecretReference(SecretKey::fromString('b'), required: false),
            new SecretReference(SecretKey::fromString('c'), required: true),
        ]);

        $names = array_map(static fn (SecretKey $k): string => $k->value(), $required->requiredKeys());

        self::assertSame(['a', 'c'], $names);
    }

    public function test_all_keys_includes_optional(): void
    {
        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('a'), required: true),
            new SecretReference(SecretKey::fromString('b'), required: false),
        ]);

        $names = array_map(static fn (SecretKey $k): string => $k->value(), $required->allKeys());

        self::assertSame(['a', 'b'], $names);
    }

    public function test_description_for_known_key(): void
    {
        $required = new RequiredSecrets([
            new SecretReference(SecretKey::fromString('db-password'), description: 'Write DB password'),
        ]);

        self::assertSame('Write DB password', $required->descriptionFor(SecretKey::fromString('db-password')));
    }

    public function test_description_for_unknown_key_is_empty(): void
    {
        $required = new RequiredSecrets([]);

        self::assertSame('', $required->descriptionFor(SecretKey::fromString('unknown')));
    }
}
