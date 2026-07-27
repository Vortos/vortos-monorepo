<?php

declare(strict_types=1);

namespace Vortos\Deploy\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Deploy\Preflight\PreflightCheckInterface;

/**
 * Tags every registered service that implements {@see PreflightCheckInterface}, so a deploy gate
 * cannot be registered and then silently never run.
 *
 * WHY THIS PASS EXISTS
 *
 * DeployExtension calls registerForAutoconfiguration(PreflightCheckInterface) and the preflight
 * runner consumes a TaggedIteratorArgument. Autoconfiguration only applies to definitions marked
 * autoconfigured, and every cross-package check was registered with a plain register() call — the
 * natural way to write it, and the way the framework's own packages all wrote it. The result was
 * that deploy:doctor collected exactly one check (Scheduler's, which happened to be autoconfigured)
 * and silently ignored the rest:
 *
 *   AlertRulesDoctorCheck, AnalyticsDoctorCheck, DetectorIndependenceDoctorCheck,
 *   LivenessIndependenceDoctorCheck, MessagingDoctorCheck, TrustedProxyDoctorCheck,
 *   SignatureVerificationCheck
 *
 * Every one was present in the container, correct, tested, and inert. A typo'd alert rule did not
 * fail a deploy; neither did an unverified image signature. The failure mode is the worst kind:
 * the gate reports nothing because it never ran, which is indistinguishable from the gate passing.
 *
 * Making the tag a consequence of implementing the interface — decided in a compiler pass, after
 * every extension has registered — removes the possibility. A package author cannot forget a step
 * that no longer exists.
 */
final class TagPreflightChecksPass implements CompilerPassInterface
{
    public const TAG = 'vortos.deploy.preflight_check';

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $id => $definition) {
            // Autoconfiguration templates and their .instanceof prototypes are not real services;
            // tagging them would inject unusable references into the runner.
            if (str_starts_with($id, '.abstract.') || str_starts_with($id, '.instanceof.')) {
                continue;
            }

            if ($definition->isAbstract() || $definition->hasTag(self::TAG)) {
                continue;
            }

            $class = $definition->getClass();

            // A parameter-driven class (%some.param%) cannot be resolved to an interface here.
            // Those are left alone rather than guessed at.
            if ($class === null || str_contains($class, '%') || !class_exists($class)) {
                continue;
            }

            if (!is_a($class, PreflightCheckInterface::class, allow_string: true)) {
                continue;
            }

            $definition->addTag(self::TAG);
        }
    }
}
