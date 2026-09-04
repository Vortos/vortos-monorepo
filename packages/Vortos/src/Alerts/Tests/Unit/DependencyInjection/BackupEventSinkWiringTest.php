<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Alerts\DependencyInjection\AlertsExtension;
use Vortos\Alerts\Integration\Backup\BackupEventAlertSink;
use Vortos\Backup\DependencyInjection\Compiler\CollectBackupEventSinksPass;

/**
 * The backup alert sink has to carry the tag that gets it collected.
 *
 * vortos-backup fans its lifecycle events out through CompositeBackupEventSink, which
 * CollectBackupEventSinksPass populates from this tag alone. The sink was registered without it
 * from the day it was written, so it was constructed and never called: across the entire life of
 * the production installation, not one backup event ever produced an alert — no failed backup, no
 * failed restore drill, no integrity or replication failure.
 *
 * The reason nobody noticed is worth remembering. The one backup signal that DOES work arrives by a
 * different route: `backup-stale` is a health-probe rule that polls the catalog, so "backups
 * stopped happening" pages while "a backup ran and failed" is silent. The dead man was covering for
 * the alarm — the same shape as the July 2026 outage, where the component that reports failures was
 * the component that had stopped.
 *
 * Asserting the tag rather than the behaviour is deliberate: the tag IS the contract, and it is the
 * single thing that was missing.
 */
final class BackupEventSinkWiringTest extends TestCase
{
    public function testTheBackupAlertSinkIsTaggedForCollection(): void
    {
        $container = $this->load();

        self::assertTrue(
            $container->hasDefinition(BackupEventAlertSink::class),
            'the backup alert sink must be registered when vortos-backup is installed',
        );

        $tags = $container->getDefinition(BackupEventAlertSink::class)->getTags();

        self::assertArrayHasKey(
            CollectBackupEventSinksPass::TAG,
            $tags,
            'an untagged sink is never collected, so every backup event is silently dropped',
        );
    }

    /**
     * And it must actually be collected — the pass is what turns the tag into a wired dependency.
     */
    public function testThePassCollectsItIntoTheCompositeSink(): void
    {
        $container = $this->load();
        $tagged = array_keys($container->findTaggedServiceIds(CollectBackupEventSinksPass::TAG));

        self::assertContains(BackupEventAlertSink::class, $tagged);
    }

    private function load(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir() . '/vortos_alerts_test');
        $container->setParameter('kernel.env', 'test');
        (new AlertsExtension())->load([], $container);

        return $container;
    }
}
