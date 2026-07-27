<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Alerts\DependencyInjection\AlertsPackage;
use Vortos\Alerts\DependencyInjection\Compiler\AlertAuditWiringPass;
use Vortos\Alerts\DependencyInjection\Compiler\AlertsExternalDefaultsPass;
use Vortos\Alerts\DependencyInjection\Compiler\DurableAlertStorePass;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorder;
use Vortos\Alerts\Preflight\AlertAuditLedgerCheck;
use Vortos\Deploy\DependencyInjection\Compiler\TagPreflightChecksPass;
use Vortos\Deploy\DependencyInjection\DeployPackage;
use Vortos\Observability\Audit\AuditHashChain;

/**
 * Pins the ORDER the alerts compiler passes actually execute in.
 *
 * AlertAuditWiringPassTest calls `->process($container)` on a container it has already populated
 * with a Connection and an AuditHashChain. That proves the pass body is correct and proves nothing
 * about whether the collaborators exist when the compiler really calls it — which is the only
 * question that had ever gone wrong here.
 *
 * It went wrong twice. First AlertsExtension::load() asked `has(Connection::class)` and lost a race
 * against extension load order, so the ledger was never registered and recorded nothing in
 * production while alerts delivered to Slack normally. The fix moved that to a compiler pass but
 * registered it at -14 — and because Symfony sorts passes by DESCENDING priority, -14 runs BEFORE
 * the -16 pass that registers the AuditHashChain fallback it depends on. The guard found no hash
 * chain and returned early, so the tamper-evident ledger still recorded nothing. A green unit suite
 * shipped a fix that changed nothing, because the ordering lived in a docblock instead of a test.
 */
final class AlertsPackagePassOrderTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    private function beforeOptimizationPassClasses(): array
    {
        $container = new ContainerBuilder();
        (new AlertsPackage())->build($container);

        return array_map(
            static fn (object $pass): string => $pass::class,
            $container->getCompiler()->getPassConfig()->getBeforeOptimizationPasses(),
        );
    }

    public function test_audit_wiring_runs_after_the_pass_that_registers_its_hash_chain(): void
    {
        $classes = $this->beforeOptimizationPassClasses();

        $defaults = array_search(AlertsExternalDefaultsPass::class, $classes, true);
        $audit    = array_search(AlertAuditWiringPass::class, $classes, true);

        self::assertIsInt($defaults, 'AlertsExternalDefaultsPass is not registered.');
        self::assertIsInt($audit, 'AlertAuditWiringPass is not registered.');
        self::assertLessThan(
            $audit,
            $defaults,
            'AlertAuditWiringPass must run AFTER AlertsExternalDefaultsPass, which registers the '
            . 'AuditHashChain fallback its guard requires. Running first makes the guard fail and '
            . 'the audit ledger silently ceases to exist.',
        );
    }

    public function test_audit_wiring_runs_before_the_pass_that_tags_its_deploy_gate(): void
    {
        // TagPreflightChecksPass is registered by DeployPackage, so both packages must build into
        // the same container for the relative order to mean anything.
        $container = new ContainerBuilder();
        (new AlertsPackage())->build($container);
        (new DeployPackage())->build($container);

        $classes = array_map(
            static fn (object $pass): string => $pass::class,
            $container->getCompiler()->getPassConfig()->getBeforeOptimizationPasses(),
        );

        $audit = array_search(AlertAuditWiringPass::class, $classes, true);
        $tag   = array_search(TagPreflightChecksPass::class, $classes, true);

        self::assertIsInt($audit, 'AlertAuditWiringPass is not registered.');
        self::assertIsInt($tag, 'TagPreflightChecksPass is not registered.');
        self::assertLessThan(
            $tag,
            $audit,
            'AlertAuditWiringPass must run BEFORE TagPreflightChecksPass, or the '
            . 'AlertAuditLedgerCheck it registers is never tagged and the deploy gate never runs.',
        );
    }

    public function test_ledger_is_registered_when_the_passes_run_in_their_real_order(): void
    {
        $container = new ContainerBuilder();
        $container->register(Connection::class, Connection::class)->setSynthetic(true);

        // Exactly the order the compiler applies them in: -15, -16, then -17.
        (new DurableAlertStorePass())->process($container);
        (new AlertsExternalDefaultsPass())->process($container);
        (new AlertAuditWiringPass())->process($container);

        self::assertTrue(
            $container->has(AuditHashChain::class),
            'AlertsExternalDefaultsPass should have registered the hash chain fallback.',
        );
        self::assertTrue(
            $container->hasDefinition(AlertAuditRecorder::class),
            'The audit recorder must exist once the passes run in their real order — this is the '
            . 'assertion that fails when the priorities are inverted.',
        );
        self::assertTrue(
            $container->hasDefinition(AlertAuditLedgerCheck::class),
            'The deploy gate must be registered alongside the recorder.',
        );
    }
}
