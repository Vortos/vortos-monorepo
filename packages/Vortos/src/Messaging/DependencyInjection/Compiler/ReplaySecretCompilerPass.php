<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Messaging\Command\ReplayDeadLetterCommand;
use Vortos\Messaging\Runtime\ConsumerRunner;

/**
 * Injects the replay signing secret into ConsumerRunner and ReplayDeadLetterCommand.
 *
 * ConsumerRunner uses it to verify that x-vortos-target-handler and
 * x-vortos-global-replays headers were produced by the internal replay command,
 * not by an external attacker.
 *
 * ReplayDeadLetterCommand uses it to sign those headers when re-producing messages.
 */
final class ReplaySecretCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ([ConsumerRunner::class, ReplayDeadLetterCommand::class] as $serviceId) {
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            $container->getDefinition($serviceId)
                ->addMethodCall('setReplaySecret', ['%vortos.messaging.replay_secret%']);
        }
    }
}
