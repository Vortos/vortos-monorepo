<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection\Compiler;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\TypedReference;

/**
 * Wires every `console.command`-tagged service into the framework {@see Application} through a
 * **lazy** {@see ContainerCommandLoader} — never by eagerly `addCommand()`-ing each one.
 *
 * ## Why lazy
 *
 * The old design added every command to the Application via `addCommand(new Reference($id))`.
 * Building the Application therefore instantiated **every** command, and any command whose
 * dependency graph reached the cache tripped `RedisConnectionFactory`'s eager `connect()` in its
 * constructor. The result: a single operator/deploy command (`vortos:release:record-manifest`,
 * `deploy:doctor`, `deploy`) could not boot without the full infra — Redis, and by the same
 * mechanism Postgres/Kafka — reachable. That is fatal for the deploy-in-image model, where these
 * commands run via `docker run <image> php bin/console <cmd>` on a host that has no infra.
 *
 * With a lazy loader, the invoked command name maps to a service id and **only that command** is
 * instantiated on demand ({@see ContainerCommandLoader::get()}); the rest — and their infra
 * dependencies — are never constructed. Combined with lazy connection factories, control-plane
 * and deploy commands boot infra-free.
 *
 * ## Liveness invariant (unchanged)
 *
 * The command services, the service locator, and the loader all hang off the **public**
 * Application definition. If the Application is missing or private, Symfony's
 * RemoveUnusedDefinitionsPass prunes the whole chain during compilation. Both conditions remain
 * hard, compile-time, fail-closed errors — not warnings.
 *
 * The name/alias/description extraction mirrors Symfony's own {@see \Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass}
 * (attribute-first, tag-attribute fallback, {@see LazyCommand} wrapping so `list` stays lazy too).
 */
class ConsoleCommandPass implements CompilerPassInterface
{
    private const LOADER_ID = 'vortos.console.command_loader';

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
                . 'wired into it via a lazy command loader — if it is private and unreachable from any '
                . 'other public service, Symfony deletes the whole command chain during compilation. This '
                . 'is a compile-time failure by design, not a warning. Register "%s" as public '
                . '(Foundation\Bootstrap\Container.php already does this for every real app).',
                Application::class,
                Application::class,
            ));
        }

        if ($taggedServices === []) {
            return;
        }

        /** @var array<string, string> $commandMap command name/alias => service id */
        $commandMap = [];
        /** @var array<string, Reference|TypedReference> $commandRefs service id => reference for the locator */
        $commandRefs = [];

        foreach ($taggedServices as $id => $tags) {
            $definition = $container->findDefinition($id);
            /** @var string|null $class */
            $class = $container->getParameterBag()->resolveValue($definition->getClass());

            if ($class === null || !$reflection = $container->getReflectionClass($class)) {
                throw new \RuntimeException(sprintf(
                    'The class of console command service "%s" could not be resolved for lazy registration.',
                    $id,
                ));
            }

            if (!$reflection->isSubclassOf(Command::class)) {
                throw new \RuntimeException(sprintf(
                    'Service "%s" (%s) is tagged "console.command" but is not a subclass of "%s". '
                    . 'Every Vortos console command must extend Command so it can be lazily loaded.',
                    $id,
                    $class,
                    Command::class,
                ));
            }

            $definition->addTag('container.no_preload');

            /** @var AsCommand|null $attribute */
            $attribute = ($reflection->getAttributes(AsCommand::class)[0] ?? null)?->newInstance();

            $rawNames = str_replace('%', '%%', $tags[0]['command'] ?? $attribute?->name ?? '');
            $aliases = explode('|', $rawNames);
            $commandName = array_shift($aliases);

            if ($isHidden = ('' === $commandName)) {
                $commandName = array_shift($aliases);
            }

            if ($commandName === null || $commandName === '') {
                throw new \RuntimeException(sprintf(
                    'Console command service "%s" (%s) has no command name. Declare it via the '
                    . '#[AsCommand] attribute (or the "command" tag attribute) so it can be registered '
                    . 'in the lazy command loader.',
                    $id,
                    $class,
                ));
            }

            // Aliases come from two places: the legacy pipe syntax (name: 'a|b') parsed above, and the
            // modern #[AsCommand(aliases: [...])] param. Symfony's own pass honours both; mirror that so
            // an alias declared either way is registered in the lazy loader's command map (e.g.
            // 'cache:warmup' → 'vortos:cache:warmup', so a stale stub can't invoke an unmapped name).
            $aliases = array_merge($aliases, $attribute?->aliases ?? []);
            $aliases = array_values(array_unique(array_filter($aliases, static fn (string $a): bool => $a !== '')));

            $commandMap[$commandName] = $id;
            foreach ($aliases as $alias) {
                $commandMap[$alias] = $id;
            }

            $definition->addMethodCall('setName', [$commandName]);
            if ($aliases !== []) {
                $definition->addMethodCall('setAliases', [$aliases]);
            }
            if ($isHidden) {
                $definition->addMethodCall('setHidden', [true]);
            }

            $description = $tags[0]['description'] ?? $attribute?->description ?? null;

            $commandRefs[$id] = new TypedReference($id, $class);

            if ($description !== null && $description !== '') {
                $escapedDescription = str_replace('%', '%%', $description);
                $definition->addMethodCall('setDescription', [$escapedDescription]);

                $lazyId = '.' . $id . '.lazy';
                $container->register($lazyId, LazyCommand::class)
                    ->setArguments([
                        $commandName,
                        $aliases,
                        $escapedDescription,
                        $isHidden,
                        new ServiceClosureArgument($commandRefs[$id]),
                    ]);

                $commandRefs[$id] = new Reference($lazyId);
            }
        }

        $container->register(self::LOADER_ID, ContainerCommandLoader::class)
            ->setPublic(false)
            ->addTag('container.no_preload')
            ->setArguments([
                ServiceLocatorTagPass::register($container, $commandRefs),
                $commandMap,
            ]);

        $applicationDefinition->addMethodCall('setCommandLoader', [new Reference(self::LOADER_ID)]);
    }
}
