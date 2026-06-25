<?php

declare(strict_types=1);

namespace Vortos\Deploy\Testing;

use Vortos\Deploy\Registry\Auth\RegistryAuthStrategyInterface;
use Vortos\Deploy\Registry\RegistryCredential;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * TCK base for all RegistryAuthStrategy drivers.
 *
 * Port-specific contract enforced here:
 *  - supports() returns true for the driver's own credential type
 *  - login() passes the secret via stdin, never in argv (security invariant)
 *  - redactTokens() returns a non-empty list so the runner can scrub output
 */
abstract class RegistryAuthStrategyConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createStrategy(): RegistryAuthStrategyInterface;

    abstract protected function createValidCredential(): RegistryCredential;

    protected function createDriver(): RegistryAuthStrategyInterface
    {
        return $this->createStrategy();
    }

    final public function test_supports_valid_credential(): void
    {
        $this->assertTrue(
            $this->createStrategy()->supports($this->createValidCredential()),
            'supports() must return true for the credential type this strategy handles.',
        );
    }

    final public function test_redact_tokens_non_empty_for_valid_credential(): void
    {
        $tokens = $this->createStrategy()->redactTokens($this->createValidCredential());

        $this->assertNotEmpty(
            $tokens,
            'redactTokens() must return at least one token to scrub from logs.',
        );
    }

    final public function test_login_passes_secret_via_stdin_not_argv(): void
    {
        $runner = new FakeCommandRunner();
        $credential = $this->createValidCredential();
        $tokens = $this->createStrategy()->redactTokens($credential);

        $this->createStrategy()->login($runner, $credential);

        $this->assertCount(1, $runner->calls, 'login() must issue exactly one docker login command.');

        $call = $runner->calls[0];
        $this->assertNotNull($call['stdin'], 'Credentials must be passed via stdin, not args.');

        foreach ($tokens as $token) {
            foreach ($call['argv'] as $arg) {
                $this->assertStringNotContainsString(
                    $token,
                    $arg,
                    'Secret token must never appear in command arguments.',
                );
            }
        }
    }

    final public function test_login_rejects_unsupported_credential(): void
    {
        $foreign = $this->createForeignCredential();
        if ($foreign === null) {
            $this->markTestSkipped('No foreign credential available for this test.');
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->createStrategy()->login(new FakeCommandRunner(), $foreign);
    }

    /**
     * Return a credential of a *different* type than createValidCredential(), or null
     * when no suitable alternative exists.
     */
    protected function createForeignCredential(): ?RegistryCredential
    {
        return null;
    }
}
