<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Preflight\PostgresConfigDriftAlertsCheck;
use Vortos\Alerts\Rule\AlertRule;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Condition\NoCondition;
use Vortos\Alerts\Severity;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;
use Vortos\OpsKit\Gate\GateDisposition;

/** RC-5: a declared PostgreSQL configuration file nothing pages on is measured and never heard. */
final class PostgresConfigDriftAlertsCheckTest extends TestCase
{
    use PreflightTestFactory;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vortos-pg-drift-alerts-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/config', 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/backup.php');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function testDeclaredConfigFileWithoutARuleRefusesTheRelease(): void
    {
        $check = new PostgresConfigDriftAlertsCheck(new AlertRuleSet([$this->probeRule('backup-freshness')]), $this->loader(declared: true));

        $finding = $check->check($this->context());

        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('postgres-config-drift', $finding->detail);
        self::assertSame(GateDisposition::Blocking, $check->disposition());
        self::assertSame('alerts.postgres_config_drift_covered', $check->id());
    }

    public function testARuleOfTheWrongKindDoesNotCount(): void
    {
        $rules = new AlertRuleSet([new AlertRule('drift', Severity::Critical, AlertRuleKind::BackupFailed, new NoCondition(), labels: ['probe' => 'postgres-config-drift'])]);

        self::assertSame(PreflightStatus::Fail, (new PostgresConfigDriftAlertsCheck($rules, $this->loader(declared: true)))->check($this->context())->status);
    }

    public function testTheRulePasses(): void
    {
        $rules = new AlertRuleSet([$this->probeRule('postgres-config-drift')]);

        self::assertSame(PreflightStatus::Pass, (new PostgresConfigDriftAlertsCheck($rules, $this->loader(declared: true)))->check($this->context())->status);
    }

    public function testNothingDeclaredIsSkipped(): void
    {
        self::assertSame(PreflightStatus::Skip, (new PostgresConfigDriftAlertsCheck(new AlertRuleSet([]), $this->loader(declared: false)))->check($this->context())->status);
        self::assertSame(PreflightStatus::Skip, (new PostgresConfigDriftAlertsCheck(new AlertRuleSet([]), null))->check($this->context())->status);
    }

    private function probeRule(string $probe): AlertRule
    {
        return new AlertRule($probe, Severity::Critical, AlertRuleKind::HealthProbeFailing, new NoCondition(), labels: ['probe' => $probe]);
    }

    private function loader(bool $declared): BackupConfigLoader
    {
        file_put_contents(
            $this->dir . '/config/backup.php',
            "<?php\nreturn \\Vortos\\Backup\\Config\\BackupConfig::create()->engine('postgres')->objectives(rpoSeconds: 300, rtoSeconds: 1800)"
            . ($declared ? "->postgresConfigFile('/etc/postgresql/postgresql.conf')" : '')
            . "\n    ->schedule(fn (\$s) => \$s->backup('0 2 * * *', kind: 'physical_base'));\n",
        );

        return new BackupConfigLoader($this->dir, 'prod');
    }
}
