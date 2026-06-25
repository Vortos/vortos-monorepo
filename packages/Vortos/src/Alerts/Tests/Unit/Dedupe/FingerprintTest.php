<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Dedupe;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Dedupe\Fingerprint;

final class FingerprintTest extends TestCase
{
    public function test_stable_for_identical_inputs(): void
    {
        $a = Fingerprint::compute('rule1', 'prod', null, ['host' => 'a']);
        $b = Fingerprint::compute('rule1', 'prod', null, ['host' => 'a']);

        self::assertSame($a, $b);
    }

    public function test_order_independent_on_labels(): void
    {
        $a = Fingerprint::compute('rule1', 'prod', null, ['host' => 'a', 'zone' => 'x']);
        $b = Fingerprint::compute('rule1', 'prod', null, ['zone' => 'x', 'host' => 'a']);

        self::assertSame($a, $b);
    }

    public function test_changes_when_rule_id_changes(): void
    {
        $a = Fingerprint::compute('rule1', 'prod', null, []);
        $b = Fingerprint::compute('rule2', 'prod', null, []);

        self::assertNotSame($a, $b);
    }

    public function test_changes_when_env_changes(): void
    {
        $a = Fingerprint::compute('rule1', 'prod', null, []);
        $b = Fingerprint::compute('rule1', 'staging', null, []);

        self::assertNotSame($a, $b);
    }

    public function test_changes_when_tenant_changes(): void
    {
        $a = Fingerprint::compute('rule1', 'prod', 'tenant-a', []);
        $b = Fingerprint::compute('rule1', 'prod', 'tenant-b', []);

        self::assertNotSame($a, $b);
    }

    public function test_changes_when_labels_change(): void
    {
        $a = Fingerprint::compute('rule1', 'prod', null, ['host' => 'a']);
        $b = Fingerprint::compute('rule1', 'prod', null, ['host' => 'b']);

        self::assertNotSame($a, $b);
    }
}
