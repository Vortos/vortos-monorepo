<?php

declare(strict_types=1);

namespace Vortos\Auth\Exception;

/**
 * Thrown when a refresh token is presented that was already consumed.
 *
 * This indicates credential theft — the legitimate holder and the attacker
 * both used the same refresh token. All tokens for the affected user are
 * revoked as a breach response (RFC 6819 §5.2.2.3).
 */
final class TokenReusedException extends AuthenticationException {}
