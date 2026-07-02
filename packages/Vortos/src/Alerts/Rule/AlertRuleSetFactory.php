<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule;

/**
 * Builds the {@see AlertRuleSet} from the application's `config/alerts.php`.
 *
 * Alerting historically had no configuration surface: AlertRuleSet was registered with an empty
 * rule list and the only way to declare rules was to override the service definition (upstream
 * P2-2). This factory adds `config/alerts.php`, which returns the declared rules directly (rules
 * carry enums and a condition object, so a typed closure/array of AlertRule is the honest surface):
 *
 *     // config/alerts.php
 *     return [
 *         new AlertRule('db-down', Severity::Critical, AlertRuleKind::Threshold, $condition, forDuration: 60),
 *     ];
 *
 * A closure form `fn(): array` is also accepted for lazy construction.
 */
final class AlertRuleSetFactory
{
    public function __invoke(string $projectDir): AlertRuleSet
    {
        $path = rtrim($projectDir, '/') . '/config/alerts.php';
        if ($projectDir === '' || !is_file($path)) {
            return new AlertRuleSet([]);
        }

        /** @var mixed $config */
        $config = require $path;

        if ($config instanceof \Closure) {
            $config = $config();
        }

        if ($config instanceof AlertRuleSet) {
            return $config;
        }

        if (!is_array($config)) {
            throw new \LogicException(sprintf(
                'config/alerts.php must return a list<%s>, an %s, or a Closure returning one; got %s.',
                AlertRule::class,
                AlertRuleSet::class,
                get_debug_type($config),
            ));
        }

        $rules = [];
        foreach ($config as $rule) {
            if (!$rule instanceof AlertRule) {
                throw new \LogicException(sprintf(
                    'config/alerts.php must contain only %s instances; got %s.',
                    AlertRule::class,
                    get_debug_type($rule),
                ));
            }
            $rules[] = $rule;
        }

        return new AlertRuleSet($rules);
    }
}
