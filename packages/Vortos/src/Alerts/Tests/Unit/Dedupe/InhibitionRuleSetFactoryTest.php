<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Dedupe;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Dedupe\AlertInhibitionSet;
use Vortos\Alerts\Dedupe\InhibitionRule;
use Vortos\Alerts\Dedupe\InhibitionRuleSetFactory;

final class InhibitionRuleSetFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-inhib-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/alert_inhibitions.php');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_absent_file_is_an_empty_set_no_suppression(): void
    {
        $set = (new InhibitionRuleSetFactory())($this->dir);

        self::assertSame([], $set->all(), 'nothing is inhibited unless the app declares it');
    }

    public function test_loads_declared_rules(): void
    {
        $this->writeConfig('<?php return [new \Vortos\Alerts\Dedupe\InhibitionRule("host-down", "svc-unreachable", 600)];');

        $set = (new InhibitionRuleSetFactory())($this->dir);

        self::assertCount(1, $set->all());
        self::assertInstanceOf(InhibitionRule::class, $set->all()[0]);
        self::assertSame('host-down', $set->all()[0]->sourceRuleId);
    }

    public function test_accepts_a_closure_form(): void
    {
        $this->writeConfig('<?php return fn(): array => [new \Vortos\Alerts\Dedupe\InhibitionRule("a", "b", 60)];');

        $set = (new InhibitionRuleSetFactory())($this->dir);

        self::assertCount(1, $set->all());
    }

    public function test_accepts_a_set_instance(): void
    {
        $this->writeConfig('<?php return new \Vortos\Alerts\Dedupe\AlertInhibitionSet([new \Vortos\Alerts\Dedupe\InhibitionRule("a", "b", 60)]);');

        $set = (new InhibitionRuleSetFactory())($this->dir);

        self::assertInstanceOf(AlertInhibitionSet::class, $set);
        self::assertCount(1, $set->all());
    }

    public function test_rejects_a_non_inhibition_rule(): void
    {
        $this->writeConfig('<?php return ["not a rule"];');

        $this->expectException(\LogicException::class);
        (new InhibitionRuleSetFactory())($this->dir);
    }

    private function writeConfig(string $php): void
    {
        file_put_contents($this->dir . '/config/alert_inhibitions.php', $php);
    }
}
