<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

/**
 * Builds the {@see AlertInhibitionSet} from the application's `config/alert_inhibitions.php`.
 *
 * Mirrors {@see \Vortos\Alerts\Rule\AlertRuleSetFactory}: the file returns the declared rules
 * directly (or a Closure returning them), so the surface is a typed list of {@see InhibitionRule}:
 *
 *     // config/alert_inhibitions.php
 *     return [
 *         new InhibitionRule(sourceRuleId: 'host-down', suppressedRuleId: 'service-unreachable', windowSeconds: 600),
 *     ];
 *
 * Absent file → empty set → no suppression. Nothing is inhibited by default; suppression is always
 * a decision the application makes on purpose.
 */
final class InhibitionRuleSetFactory
{
    public function __invoke(string $projectDir): AlertInhibitionSet
    {
        $path = rtrim($projectDir, '/') . '/config/alert_inhibitions.php';
        if ($projectDir === '' || !is_file($path)) {
            return new AlertInhibitionSet([]);
        }

        /** @var mixed $config */
        $config = require $path;

        if ($config instanceof \Closure) {
            $config = $config();
        }

        if ($config instanceof AlertInhibitionSet) {
            return $config;
        }

        if (!is_array($config)) {
            throw new \LogicException(sprintf(
                'config/alert_inhibitions.php must return a list<%s>, an %s, or a Closure returning one; got %s.',
                InhibitionRule::class,
                AlertInhibitionSet::class,
                get_debug_type($config),
            ));
        }

        foreach ($config as $rule) {
            if (!$rule instanceof InhibitionRule) {
                throw new \LogicException(sprintf(
                    'config/alert_inhibitions.php must contain only %s instances; got %s.',
                    InhibitionRule::class,
                    get_debug_type($rule),
                ));
            }
        }

        /** @var list<InhibitionRule> $config */
        return new AlertInhibitionSet($config);
    }
}
