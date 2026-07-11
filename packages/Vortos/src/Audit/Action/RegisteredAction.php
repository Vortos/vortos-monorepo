<?php

declare(strict_types=1);

namespace Vortos\Audit\Action;

use Vortos\Audit\Enum\Scope;
use Vortos\Audit\Enum\Sensitivity;

/**
 * One entry in the controlled action vocabulary.
 *
 * Actions are declared, not free-form: every audited action has a stable dotted key
 * (e.g. `member.role.granted`, `flag.publish`), a human description, a default
 * sensitivity, and the scope it belongs in. This is what keeps the trail queryable at
 * scale instead of a pile of ad-hoc strings.
 */
final readonly class RegisteredAction
{
    public function __construct(
        public string      $key,
        public string      $description,
        public Sensitivity $sensitivity = Sensitivity::Normal,
        public Scope       $scope = Scope::Tenant,
    ) {
        if (!preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $key)) {
            throw new \InvalidArgumentException(
                "Audit action key '{$key}' must be lower-case dotted (e.g. 'member.role.granted').",
            );
        }
    }
}
