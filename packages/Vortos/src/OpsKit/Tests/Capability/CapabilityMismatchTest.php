<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Capability;

use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Driver\Capability\CapabilityMismatch;

final class CapabilityMismatchTest extends TestCase
{
    public function test_is_empty_reflects_contents(): void
    {
        $this->assertTrue((new CapabilityMismatch([], []))->isEmpty());
        $this->assertFalse((new CapabilityMismatch(['x'], []))->isEmpty());
        $this->assertFalse((new CapabilityMismatch([], ['n' => ['required' => 1, 'actual' => 2]]))->isEmpty());
    }

    public function test_message_names_driver_concern_and_missing_capability(): void
    {
        $mismatch = new CapabilityMismatch(['rolling_across_nodes'], []);
        $message = $mismatch->message('ssh-compose', 'deploy');

        $this->assertStringContainsString("ssh-compose", $message);
        $this->assertStringContainsString("deploy", $message);
        $this->assertStringContainsString("rolling_across_nodes", $message);
    }

    public function test_message_renders_constraint_violation_with_undeclared_actual(): void
    {
        $mismatch = new CapabilityMismatch([], [
            'cert_ttl' => ['required' => 300, 'actual' => null],
            'mode' => ['required' => 'strict', 'actual' => 'loose'],
        ]);
        $message = $mismatch->message('k8s', 'deploy');

        $this->assertStringContainsString("cert_ttl' must be 300", $message);
        $this->assertStringContainsString('(undeclared)', $message);
        $this->assertStringContainsString("mode' must be 'strict' but driver declares 'loose'", $message);
    }
}
