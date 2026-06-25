<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Iac\Command\IacDriftCommand;
use Vortos\Iac\Lifecycle\IacDriftAuditorInterface;
use Vortos\Iac\Lifecycle\IacDriftReport;

final class IacDriftCommandTest extends TestCase
{
    public function test_no_drift_exits_zero(): void
    {
        $auditor = new class implements IacDriftAuditorInterface {
            public function audit(string $environment): IacDriftReport { return IacDriftReport::clean(); }
        };

        $tester = new CommandTester(new IacDriftCommand($auditor));
        $tester->execute(['--env' => 'dev']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No infrastructure drift', $tester->getDisplay());
    }

    public function test_drift_exits_one(): void
    {
        $auditor = new class implements IacDriftAuditorInterface {
            public function audit(string $environment): IacDriftReport { return IacDriftReport::drifted('1 resource changed'); }
        };

        $tester = new CommandTester(new IacDriftCommand($auditor));
        $tester->execute(['--env' => 'dev']);
        $this->assertSame(1, $tester->getStatusCode());
    }

    public function test_unreachable_exits_one(): void
    {
        $auditor = new class implements IacDriftAuditorInterface {
            public function audit(string $environment): IacDriftReport { return IacDriftReport::unreachable('backend down'); }
        };

        $tester = new CommandTester(new IacDriftCommand($auditor));
        $tester->execute(['--env' => 'dev']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('unreachable', $tester->getDisplay());
    }

    public function test_json_output(): void
    {
        $auditor = new class implements IacDriftAuditorInterface {
            public function audit(string $environment): IacDriftReport { return IacDriftReport::clean(); }
        };

        $tester = new CommandTester(new IacDriftCommand($auditor));
        $tester->execute(['--env' => 'dev', '--json' => true]);
        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertFalse($decoded['has_drift']);
    }
}
