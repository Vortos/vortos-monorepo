<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class ConsoleCommandPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $taggedServices = $container->findTaggedServiceIds('console.command');

        if (!$container->has(Application::class)) {
            if ($taggedServices !== []) {
                throw new \RuntimeException(sprintf(
                    'Container has %d service(s) tagged "console.command" (%s) but no "%s" '
                    . 'service is registered to keep them alive. Symfony\'s RemoveUnusedDefinitionsPass '
                    . 'will silently delete these commands and everything they depend on — this is '
                    . 'not a warning, it is a compile-time failure by design. This container was '
                    . 'assembled without Foundation\'s bootstrap (Foundation\Bootstrap\Container.php), '
                    . 'which registers a public Application service via every real app\'s package '
                    . 'discovery. Load FoundationPackage::build() and register a public "%s" service '
                    . 'before compiling.',
                    count($taggedServices),
                    implode(', ', array_keys($taggedServices)),
                    Application::class,
                    Application::class,
                ));
            }

            return;
        }

        $applicationDefinition = $container->findDefinition(Application::class);

        if (!$applicationDefinition->isPublic() && $taggedServices !== []) {
            throw new \RuntimeException(sprintf(
                '"%s" is registered but not public. Console commands tagged "console.command" are '
                . 'wired into it via method calls — if it is private and unreachable from any other '
                . 'public service, Symfony deletes the whole command chain during compilation. This '
                . 'is a compile-time failure by design, not a warning. Register "%s" as public '
                . '(Foundation\Bootstrap\Container.php already does this for every real app).',
                Application::class,
                Application::class,
            ));
        }

        foreach ($taggedServices as $id => $tags) {
            $applicationDefinition->addMethodCall('addCommand', [new Reference($id)]);
        }
    }
}
