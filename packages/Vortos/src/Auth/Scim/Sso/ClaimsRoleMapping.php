<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Sso;

/**
 * One mapping rule: an IdP group/claim pattern → a platform role slug.
 *
 * Matching is case-insensitive exact match by default. Set $isPattern=true to
 * treat $idpIdentifier as a regex (anchored). Patterns over 200 chars are rejected.
 */
final class ClaimsRoleMapping
{
    public function __construct(
        /** The IdP group name, claim value, or regex pattern. */
        public readonly string $idpIdentifier,
        /** The platform role slug this mapping grants. */
        public readonly string $platformRole,
        public readonly bool $isPattern = false,
    ) {
        if ($this->isPattern && strlen($this->idpIdentifier) > 200) {
            throw new \InvalidArgumentException('ClaimsRoleMapping pattern too long (max 200 chars)');
        }
    }

    public function matchesGroup(string $idpGroup): bool
    {
        return $this->matches($idpGroup);
    }

    public function matchesClaim(string $claim): bool
    {
        return $this->matches($claim);
    }

    private function matches(string $value): bool
    {
        if ($this->isPattern) {
            $result = @preg_match('/' . str_replace('/', '\/', $this->idpIdentifier) . '/i', $value);

            return $result === 1;
        }

        return strtolower($this->idpIdentifier) === strtolower($value);
    }
}
