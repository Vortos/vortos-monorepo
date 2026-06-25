<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Driver\Registry\GhcrRegistry;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\Oci\NullImageSigner;
use Vortos\Deploy\Registry\PatTokenCredential;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Secrets\Value\SecretValue;

final class GhcrRegistryAuthTest extends TestCase
{
    public function test_push_with_credential_logs_in_first(): void
    {
        $runner = new FakeCommandRunner();
        $credential = new PatTokenCredential('actor', SecretValue::fromString('ghp_token'));

        // Provide enough successful results for login + push + digest
        $runner->addResult(new CommandResult(0, '', '', 0.01, [])); // docker login
        $runner->addResult(new CommandResult(0, '', '', 0.01, [])); // docker push
        $runner->addResult(new CommandResult(0, '', '', 0.01, [])); // crane digest (fails → skip)

        // For the digest fallback we need one more
        $runner->addResult(new CommandResult(0, '{"schemaVersion":2}', '', 0.01, [])); // imagetools

        $registry = new GhcrRegistry($runner, new NullImageSigner(), $credential);
        $image = ImageReference::fromArray(['repository' => 'ghcr.io/org/app', 'tag' => 'sha-abc123']);

        try {
            $registry->push($image);
        } catch (\Throwable) {
            // digest parsing may fail in unit test — what matters is login order
        }

        $this->assertNotEmpty($runner->calls);
        $firstCall = $runner->calls[0];
        $this->assertSame('docker', $firstCall['argv'][0]);
        $this->assertSame('login', $firstCall['argv'][1]);
        $this->assertSame('ghcr.io', $firstCall['argv'][2]);
    }

    public function test_push_without_credential_skips_login(): void
    {
        $runner = new FakeCommandRunner();
        $registry = new GhcrRegistry($runner, new NullImageSigner());
        $image = ImageReference::fromArray(['repository' => 'ghcr.io/org/app', 'tag' => 'sha-abc']);

        try {
            $registry->push($image);
        } catch (\Throwable) {
        }

        // No login command should be the first call
        if (!empty($runner->calls)) {
            $this->assertNotSame('login', $runner->calls[0]['argv'][1] ?? '');
        }
    }

    public function test_token_passed_via_stdin_on_login(): void
    {
        $runner = new FakeCommandRunner();
        $token = 'ghp_super_secret';
        $credential = new PatTokenCredential('actor', SecretValue::fromString($token));
        $registry = new GhcrRegistry($runner, new NullImageSigner(), $credential);
        $image = ImageReference::fromArray(['repository' => 'ghcr.io/org/app', 'tag' => 'sha-abc']);

        try {
            $registry->push($image);
        } catch (\Throwable) {
        }

        $loginCall = $runner->calls[0] ?? null;
        $this->assertNotNull($loginCall);
        $this->assertSame($token, $loginCall['stdin']);

        foreach ($loginCall['argv'] as $arg) {
            $this->assertStringNotContainsString($token, $arg);
        }
    }
}
