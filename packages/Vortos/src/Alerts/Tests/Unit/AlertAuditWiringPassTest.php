<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Vortos\Alerts\DependencyInjection\Compiler\AlertAuditWiringPass;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorder;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorderInterface;
use Vortos\Observability\Audit\AuditHashChain;

final class AlertAuditWiringPassTest extends TestCase
{
    /**
     * The production failure. AlertsExtension::load() bailed on
     * `if (!$container->has(Connection::class)) return;` — a cross-package availability question
     * asked before the other extension had necessarily registered anything. It lost that race, the
     * ledger was never registered, and alerts delivered to Slack while the audit trail held zero
     * rows with no error anywhere.
     */
    public function test_registers_the_ledger_when_a_connection_exists(): void
    {
        $container = $this->containerWithDependencies();

        (new AlertAuditWiringPass())->process($container);

        self::assertTrue($container->hasDefinition(AlertAuditRecorder::class));
        self::assertTrue($container->hasAlias(AlertAuditRecorderInterface::class));
    }

    /**
     * The key must be an env PLACEHOLDER, not a value read while compiling. Reading $_ENV here
     * bakes one environment's observation into the container — which is how the recorder came to
     * be omitted on a host where the key was set.
     */
    public function test_the_signing_key_is_resolved_at_runtime_not_compile_time(): void
    {
        $container = $this->containerWithDependencies();

        (new AlertAuditWiringPass())->process($container);

        self::assertSame(
            '%env(ALERTS_AUDIT_HMAC_KEY)%',
            $container->getDefinition(AlertAuditRecorder::class)->getArgument('$hmacKey'),
        );
    }

    public function test_does_nothing_without_a_connection(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(AuditHashChain::class, new Definition(AuditHashChain::class));

        (new AlertAuditWiringPass())->process($container);

        self::assertFalse($container->hasDefinition(AlertAuditRecorder::class));
    }

    public function test_is_idempotent(): void
    {
        $container = $this->containerWithDependencies();

        $pass = new AlertAuditWiringPass();
        $pass->process($container);
        $pass->process($container);

        self::assertTrue($container->hasDefinition(AlertAuditRecorder::class));
    }

    private function containerWithDependencies(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(Connection::class, (new Definition(Connection::class))->setSynthetic(true));
        $container->setDefinition(AuditHashChain::class, (new Definition(AuditHashChain::class))->setSynthetic(true));

        return $container;
    }
}
