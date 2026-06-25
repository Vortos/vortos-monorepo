<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Sso;

final class ClaimsRoleMapping
{
    private const MAX_PATTERN_LENGTH = 200;
    private const SAFE_BACKTRACK_LIMIT = 10_000;

    private const EVIL_QUANTIFIER_NESTING = '/
        \(              # opening group
        [^)]*           # group contents
        [+*]            # inner quantifier
        [^)]*           # more group contents
        \)              # closing group
        [+*]            # outer quantifier on the group
    /x';

    private readonly string $compiledPattern;

    public function __construct(
        public readonly string $idpIdentifier,
        public readonly string $platformRole,
        public readonly bool $isPattern = false,
    ) {
        if ($this->isPattern) {
            if (strlen($this->idpIdentifier) > self::MAX_PATTERN_LENGTH) {
                throw new \InvalidArgumentException('ClaimsRoleMapping pattern too long (max 200 chars)');
            }

            if (preg_match(self::EVIL_QUANTIFIER_NESTING, $this->idpIdentifier) === 1) {
                throw new \InvalidArgumentException(sprintf(
                    'ClaimsRoleMapping pattern contains catastrophic backtracking risk: "%s"',
                    $this->idpIdentifier,
                ));
            }

            $this->compiledPattern = '/' . str_replace('/', '\/', $this->idpIdentifier) . '/i';

            $result = @preg_match($this->compiledPattern, '');
            if ($result === false) {
                throw new \InvalidArgumentException(sprintf(
                    'ClaimsRoleMapping pattern is invalid regex: "%s" — %s',
                    $this->idpIdentifier,
                    preg_last_error_msg(),
                ));
            }
        } else {
            $this->compiledPattern = '';
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
        if (!$this->isPattern) {
            return strtolower($this->idpIdentifier) === strtolower($value);
        }

        $prevLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::SAFE_BACKTRACK_LIMIT);

        try {
            $result = preg_match($this->compiledPattern, $value);
        } finally {
            ini_set('pcre.backtrack_limit', $prevLimit !== false ? $prevLimit : '1000000');
        }

        if ($result === false) {
            $error = preg_last_error();
            if ($error === \PREG_BACKTRACK_LIMIT_ERROR) {
                throw RoleMappingException::backtrackLimitExceeded($this->idpIdentifier, $value);
            }
            throw RoleMappingException::runtimeMatchFailure($this->idpIdentifier, preg_last_error_msg());
        }

        return $result === 1;
    }
}
