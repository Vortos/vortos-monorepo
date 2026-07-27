<?php

declare(strict_types=1);

namespace Vortos\Debug\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Debug\DependencyInjection\Compiler\DebugContainerPass;
use Vortos\Debug\DependencyInjection\Compiler\DebugRoutesPass;
use Vortos\Foundation\Contract\PackageInterface;

final class DebugPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new DebugExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Runs after RouteCompilerPass (priority 80) — collects route metadata
        $container->addCompilerPass(
            new DebugRoutesPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            70,
        );

        // AFTER_REMOVING, so the snapshot is the container that actually exists at runtime.
        //
        // This used to run at BEFORE_OPTIMIZATION priority 60 — ahead of every negative-priority
        // pass and every removal pass — so it reported a container that never ran. It listed
        // InMemoryAlertStateStore, which DurableAlertStorePass supersedes and removal then deletes,
        // and omitted AlertAuditRecorder and DbalAlertStateStore, which later passes register. It
        // showed 19 services carrying vortos.deploy.preflight_check when 25 carry it, missing
        // exactly the six that TagPreflightChecksPass (-48) adds. A debug command that reports
        // services which do not exist and hides ones that do is worse than no debug command: it
        // was read as evidence that a fix had not worked, twice.
        //
        // The old placement was justified as "must run before ResolveNamedArgumentsPass converts
        // $name → positional index". That constraint applied only to this pass setting its OWN
        // arguments by name; they are set positionally now. Argument names for every other service
        // are recovered by reflecting the constructor, so the detail view keeps its labels.
        $container->addCompilerPass(
            new DebugContainerPass(),
            PassConfig::TYPE_AFTER_REMOVING,
            0,
        );
    }
}
