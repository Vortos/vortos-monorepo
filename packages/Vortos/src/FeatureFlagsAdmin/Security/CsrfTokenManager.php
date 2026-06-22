<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Security;

use Symfony\Component\HttpFoundation\RequestStack;

final class CsrfTokenManager
{
    private const SESSION_KEY = '_vortos_admin_csrf';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    public function getToken(): string
    {
        $session = $this->requestStack->getSession();

        $token = $session->get(self::SESSION_KEY);

        if ($token === null || !is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function isValid(string $token): bool
    {
        $expected = $this->requestStack->getSession()->get(self::SESSION_KEY);

        if ($expected === null || !is_string($expected)) {
            return false;
        }

        return hash_equals($expected, $token);
    }

    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->requestStack->getSession()->set(self::SESSION_KEY, $token);

        return $token;
    }
}
