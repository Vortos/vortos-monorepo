<?php

declare(strict_types=1);

namespace Vortos\Foundation\DependencyInjection;

/**
 * Thrown at container-compile time when a package's entry point has a HARD dependency on a
 * capability that another package must provide, and that capability is absent.
 *
 * This is the fail-loud half of the cross-package wiring discipline: a library service that
 * genuinely cannot exist without a collaborator throws this (naming the package to install)
 * rather than silently vanishing. Operator entry points (console commands) prefer
 * register-always + a runtime FAILURE with the same remediation string, so `list` still shows
 * them — see {@see \Vortos\Foundation\DependencyInjection\Compiler\ConditionalWiringPass}.
 */
final class MissingCapabilityException extends \LogicException
{
    public function __construct(
        public readonly string $consumer,
        public readonly string $missing,
        public readonly string $install,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                '%s requires %s. Install %s and wire it. Refusing to compile a component that '
                . 'cannot see the capability it depends on.',
                $consumer,
                $missing,
                $install,
            ),
            0,
            $previous,
        );
    }
}
