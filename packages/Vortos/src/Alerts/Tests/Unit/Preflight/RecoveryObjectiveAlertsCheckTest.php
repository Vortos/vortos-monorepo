<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Preflight\RecoveryObjectiveAlertsCheck;
use Vortos\Alerts\Rule\AlertRule;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Condition\NoCondition;
use Vortos\Alerts\Severity;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\OpsKit\Gate\GateDisposition;

/**
 * An objective nothing pages on is the FB-66 defect again: measured, and never heard.
 */
final class RecoveryObjectiveAlertsCheckTest extends TestCase
{
    use PreflightTestFactory;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-objective-alerts-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/config', 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/backup.php');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function testDeclaredObjectivesWithoutRulesRefuseTheRelease(): void
    {
        $check = new RecoveryObjectiveAlertsCheck(new AlertRuleSet([$this->probeRule('backup-freshness')]), $this->loader(declared: true));

        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('recovery-point-objective', $finding->detail);
        self::assertStringContainsString('recovery-time-objective', $finding->detail);
        self::assertSame(GateDisposition::Blocking, $check->disposition());
    }

    public function testOneMissingRuleIsStillRefused(): void
    {
        $check = new RecoveryObjectiveAlertsCheck(new AlertRuleSet([$this->probeRule('recovery-point-objective')]), $this->loader(declared: true));

        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringNotContainsString('recovery-point-objective', $finding->detail);
        self::assertStringContainsString('recovery-time-objective', $finding->detail);
    }

    /** A rule of the wrong kind names the probe but can never page on it. */
    public function testARuleOfTheWrongKindDoesNotCount(): void
    {
        $rules = new AlertRuleSet([
            new AlertRule('rpo', Severity::Critical, AlertRuleKind::BackupFailed, new NoCondition(), labels: ['probe' => 'recovery-point-objective']),
            $this->probeRule('recovery-time-objective'),
        ]);

        self::assertSame(PreflightStatus::Fail, (new RecoveryObjectiveAlertsCheck($rules, $this->loader(declared: true)))->check($this->context())->status);
    }

    public function testBothRulesPass(): void
    {
        $rules = new AlertRuleSet([$this->probeRule('recovery-point-objective'), $this->probeRule('recovery-time-objective')]);

        self::assertSame(PreflightStatus::Pass, (new RecoveryObjectiveAlertsCheck($rules, $this->loader(declared: true)))->check($this->context())->status);
    }

    public function testNoBackupConfigurationIsSkipped(): void
    {
        self::assertSame(PreflightStatus::Skip, (new RecoveryObjectiveAlertsCheck(new AlertRuleSet([]), $this->loader(declared: false)))->check($this->context())->status);
        self::assertSame(PreflightStatus::Skip, (new RecoveryObjectiveAlertsCheck(new AlertRuleSet([]), null))->check($this->context())->status);
    }

    private function probeRule(string $probe): AlertRule
    {
        return new AlertRule($probe, Severity::Critical, AlertRuleKind::HealthProbeFailing, new NoCondition(), labels: ['probe' => $probe]);
    }

    private function loader(bool $declared): BackupConfigLoader
    {
        if ($declared) {
            file_put_contents(
                $this->dir . '/config/backup.php',
                "<?php\nreturn \\Vortos\\Backup\\Config\\BackupConfig::create()->engine('postgres')->objectives(rpoSeconds: 300, rtoSeconds: 1800)\n"
                . "    ->schedule(fn (\$s) => \$s->backup('0 2 * * *', kind: 'physical_base'));\n",
            );
        }

        return new BackupConfigLoader($this->dir, 'prod');
    }
}
