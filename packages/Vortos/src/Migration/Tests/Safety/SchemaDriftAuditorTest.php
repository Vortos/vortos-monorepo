<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Safety;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Safety\SchemaDriftAuditor;
use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Schema\MigrationOwnership;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Service\MigrationDriftDetectorInterface;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;

final class SchemaDriftAuditorTest extends TestCase
{
    public function test_reports_no_drift_when_all_clean(): void
    {
        $registry = $this->registryWith([
            $this->descriptor('TestModule'),
        ]);
        $detector = $this->detectorReturning(new MigrationDriftReport(MigrationDriftReport::CompatibleExisting));

        $auditor = new SchemaDriftAuditor($registry, $detector);
        $findings = $auditor->audit();

        $this->assertCount(0, $findings);
        $this->assertFalse($auditor->hasDrift());
    }

    public function test_reports_drift_when_partial_detected(): void
    {
        $registry = $this->registryWith([
            $this->descriptor('TestModule'),
        ]);
        $detector = $this->detectorReturning(
            new MigrationDriftReport(MigrationDriftReport::Partial, null, [], ['missing_table'], [], [], []),
        );

        $auditor = new SchemaDriftAuditor($registry, $detector);
        $findings = $auditor->audit();

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->hasDrift);
        $this->assertTrue($auditor->hasDrift());
    }

    public function test_fails_closed_when_detector_throws(): void
    {
        $registry = $this->registryWith([
            $this->descriptor('BrokenModule'),
        ]);

        $detector = $this->createMock(MigrationDriftDetectorInterface::class);
        $detector->method('detect')->willThrowException(new \RuntimeException('DB unreachable'));

        $auditor = new SchemaDriftAuditor($registry, $detector);
        $findings = $auditor->audit();

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->hasDrift);
        $this->assertTrue($findings[0]->unreachable);
    }

    public function test_multiple_modules_all_checked(): void
    {
        $descA = $this->descriptorWithClass('ModuleA', 'MigrationA');
        $descB = $this->descriptorWithClass('ModuleB', 'MigrationB');

        $registry = $this->registryWith([$descA, $descB]);

        $results = [
            new MigrationDriftReport(MigrationDriftReport::CompatibleExisting),
            new MigrationDriftReport(MigrationDriftReport::Partial, null, [], ['t1'], [], [], []),
        ];
        $detector = $this->createMock(MigrationDriftDetectorInterface::class);
        $detector->method('detect')->willReturnOnConsecutiveCalls(...$results);

        $auditor = new SchemaDriftAuditor($registry, $detector);
        $findings = $auditor->audit();

        $this->assertCount(1, $findings);
        $this->assertSame('ModuleB', $findings[0]->module);
    }

    /** @param list<ModuleMigrationDescriptor> $descriptors */
    private function registryWith(array $descriptors): ModuleMigrationRegistryInterface
    {
        $map = [];
        foreach ($descriptors as $d) {
            $map[$d->class()] = $d;
        }
        ksort($map);

        $registry = $this->createMock(ModuleMigrationRegistryInterface::class);
        $registry->method('descriptorsByClass')->willReturn($map);

        return $registry;
    }

    private function detectorReturning(MigrationDriftReport $report): MigrationDriftDetectorInterface
    {
        $detector = $this->createMock(MigrationDriftDetectorInterface::class);
        $detector->method('detect')->willReturn($report);

        return $detector;
    }

    private function descriptor(string $moduleName): ModuleMigrationDescriptor
    {
        return $this->descriptorWithClass($moduleName, $moduleName . 'Migration');
    }

    private function descriptorWithClass(string $moduleName, string $class): ModuleMigrationDescriptor
    {
        return new ModuleMigrationDescriptor(
            source: 'test.sql',
            class: $class,
            module: $moduleName,
            filename: 'test.php',
            ownership: new MigrationOwnership(['users'], []),
        );
    }
}
