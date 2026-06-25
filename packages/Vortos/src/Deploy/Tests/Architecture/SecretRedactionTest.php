<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Execution\CommandResult;
use Vortos\Deploy\State\StepOutcome;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\State\StepStatus;

final class SecretRedactionTest extends TestCase
{
    public function test_command_result_redacts_known_secrets(): void
    {
        $secret = 'ghp_SuperSecretGitHubToken123';
        $result = new CommandResult(
            0,
            "Logged in with {$secret}",
            "auth: {$secret}",
            0.1,
            [$secret],
        );

        $this->assertStringNotContainsString($secret, $result->redactedStdout());
        $this->assertStringNotContainsString($secret, $result->redactedStderr());

        $array = $result->toArray();
        $this->assertStringNotContainsString($secret, $array['stdout']);
        $this->assertStringNotContainsString($secret, $array['stderr']);
    }

    public function test_step_outcome_never_stores_raw_secrets(): void
    {
        $outcome = new StepOutcome(
            0,
            StepAction::PullImage,
            StepStatus::Success,
            'pulled image (auth ***)',
        );

        $array = $outcome->toArray();
        $this->assertStringNotContainsString('ghp_', $array['result']);
    }

    public function test_multiple_secrets_redacted(): void
    {
        $secret1 = 'password123';
        $secret2 = 'apikey456';
        $result = new CommandResult(
            0,
            "auth {$secret1} and key {$secret2}",
            '',
            0.1,
            [$secret1, $secret2],
        );

        $this->assertStringNotContainsString($secret1, $result->redactedStdout());
        $this->assertStringNotContainsString($secret2, $result->redactedStdout());
    }
}
