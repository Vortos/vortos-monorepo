<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Health\DependencyInjection\HealthExtension;
use Vortos\Health\Preflight\DetectorIndependenceDoctorCheck;

/**
 * Pins HOW the detector-independence check is wired, not what it concludes.
 *
 * DetectorIndependenceDoctorCheckTest constructs the check directly and proves its logic. That
 * logic was never wrong. What was wrong was the wiring: HealthExtension::load() computed
 *
 *     $heartbeatConfigured = class_exists(HeartbeatEmitterInterface::class)
 *         && $container->has(HeartbeatEmitterInterface::class);
 *
 * and froze the result into a constructor argument. class_exists() is order-free and only proves
 * the package is installed; the has() beside it is order-dependent and is the one that decides.
 * Extensions load in arbitrary order, so on a host where the emitter WAS registered the boolean
 * was still baked in as false — and the check failed a production deploy reporting a detector
 * missing that existed. The deploy was correct to be fail-closed; the input was a lie.
 *
 * The fix is that the heartbeat arrives as an optional Reference resolved after every extension
 * has loaded. These tests fail if anyone reintroduces a compile-time boolean.
 */
final class DetectorIndependenceWiringTest extends TestCase
{
    private function checkArguments(): array
    {
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        (new HealthExtension())->load([], $container);

        self::assertTrue(
            $container->hasDefinition(DetectorIndependenceDoctorCheck::class),
            'HealthExtension should register the detector-independence check.',
        );

        return $container->getDefinition(DetectorIndependenceDoctorCheck::class)->getArguments();
    }

    public function test_heartbeat_is_injected_as_a_reference_not_a_compile_time_boolean(): void
    {
        $args = $this->checkArguments();

        self::assertArrayHasKey('$heartbeat', $args, 'The heartbeat argument should be named $heartbeat.');

        $heartbeat = $args['$heartbeat'];

        self::assertNotIsBool(
            $heartbeat,
            'The heartbeat must NOT be a compile-time boolean. Whether another package registered '
            . 'the emitter cannot be answered during load(), and freezing that answer failed a '
            . 'production deploy on a host where the emitter was wired.',
        );

        self::assertInstanceOf(
            Reference::class,
            $heartbeat,
            'The heartbeat must be injected as a Reference so the container resolves it after all '
            . 'extensions have loaded.',
        );

        self::assertSame(
            ContainerInterface::NULL_ON_INVALID_REFERENCE,
            $heartbeat->getInvalidBehavior(),
            'vortos-observability is optional, so the reference must degrade to null rather than '
            . 'throwing when the emitter is genuinely absent.',
        );
    }

    public function test_uptime_driver_key_is_a_declared_env_reference_not_an_inline_read(): void
    {
        $args = $this->checkArguments();

        self::assertArrayHasKey('$configuredUptimeDriverKey', $args);
        self::assertSame(
            '%env(string:UPTIME_MONITOR_DRIVER)%',
            $args['$configuredUptimeDriverKey'],
            'The container compiles in a clean environment, so an inline $_ENV read resolves '
            . 'against the build host rather than the runtime host. Env access is a declared '
            . 'reference — see Foundation\Config\Env.',
        );
    }

    private static function assertNotIsBool(mixed $value, string $message): void
    {
        self::assertFalse(is_bool($value), $message);
    }
}
