<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Driver\Terraform;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Driver\Terraform\BinaryResolver;
use Vortos\Iac\Driver\Terraform\ProcessOutcome;
use Vortos\Iac\Driver\Terraform\ProcessRunnerInterface;
use Vortos\Iac\Exception\IacBinaryNotFoundException;

final class BinaryResolverTest extends TestCase
{
    public function test_prefers_tofu(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome
            {
                if ($argv[0] === 'which' && ($argv[1] ?? '') === 'tofu') {
                    return new ProcessOutcome(0, "/usr/local/bin/tofu\n", '', 5);
                }
                if (str_contains($argv[0], 'tofu') && in_array('version', $argv, true)) {
                    return new ProcessOutcome(0, '{"terraform_version":"1.8.0"}', '', 5);
                }
                return new ProcessOutcome(1, '', '', 5);
            }
        };

        $resolver = new BinaryResolver($runner);
        $this->assertSame('/usr/local/bin/tofu', $resolver->resolve());
        $this->assertSame('1.8.0', $resolver->version());
        $this->assertSame('tofu', $resolver->binaryName());
    }

    public function test_falls_back_to_terraform(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome
            {
                if ($argv[0] === 'which' && ($argv[1] ?? '') === 'tofu') {
                    return new ProcessOutcome(1, '', '', 5);
                }
                if ($argv[0] === 'which' && ($argv[1] ?? '') === 'terraform') {
                    return new ProcessOutcome(0, "/usr/bin/terraform\n", '', 5);
                }
                if (str_contains($argv[0], 'terraform') && in_array('version', $argv, true)) {
                    return new ProcessOutcome(0, '{"terraform_version":"1.9.0"}', '', 5);
                }
                return new ProcessOutcome(1, '', '', 5);
            }
        };

        $resolver = new BinaryResolver($runner);
        $this->assertSame('/usr/bin/terraform', $resolver->resolve());
        $this->assertSame('1.9.0', $resolver->version());
    }

    public function test_throws_when_neither_found(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome
            {
                return new ProcessOutcome(1, '', '', 5);
            }
        };

        $this->expectException(IacBinaryNotFoundException::class);
        (new BinaryResolver($runner))->resolve();
    }

    public function test_respects_binary_hint(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome
            {
                if ($argv[0] === 'which' && ($argv[1] ?? '') === '/custom/tofu') {
                    return new ProcessOutcome(0, "/custom/tofu\n", '', 5);
                }
                if (str_contains($argv[0], 'tofu') && in_array('version', $argv, true)) {
                    return new ProcessOutcome(0, '{"terraform_version":"2.0.0"}', '', 5);
                }
                return new ProcessOutcome(1, '', '', 5);
            }
        };

        $resolver = new BinaryResolver($runner);
        $this->assertSame('/custom/tofu', $resolver->resolve('/custom/tofu'));
    }

    public function test_caches_result(): void
    {
        $callCount = 0;
        $runner = new class($callCount) implements ProcessRunnerInterface {
            public function __construct(private int &$callCount) {}
            public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome
            {
                $this->callCount++;
                if ($argv[0] === 'which') {
                    return new ProcessOutcome(0, "/usr/bin/tofu\n", '', 5);
                }
                return new ProcessOutcome(0, '{"terraform_version":"1.8.0"}', '', 5);
            }
        };

        $resolver = new BinaryResolver($runner);
        $resolver->resolve();
        $resolver->resolve();
        $this->assertSame(2, $callCount);
    }

    public function test_version_fallback_to_text_output(): void
    {
        $runner = new class implements ProcessRunnerInterface {
            public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome
            {
                if ($argv[0] === 'which') {
                    return new ProcessOutcome(0, "/usr/bin/tofu\n", '', 5);
                }
                if (in_array('-json', $argv, true)) {
                    return new ProcessOutcome(1, '', '', 5);
                }
                if (in_array('--version', $argv, true)) {
                    return new ProcessOutcome(0, "OpenTofu v1.7.3\n", '', 5);
                }
                return new ProcessOutcome(1, '', '', 5);
            }
        };

        $resolver = new BinaryResolver($runner);
        $resolver->resolve();
        $this->assertSame('1.7.3', $resolver->version());
    }
}
