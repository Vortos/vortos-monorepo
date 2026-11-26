<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt;

/**
 * A session family: every session descended from one sign-in.
 *
 * The session id (`sid`, the refresh token's jti) changes on every rotation, so nothing that must
 * outlive a refresh can hold on to it: a grant bound to "this staff session" stops matching fifteen
 * minutes later. The family id is minted at sign-in and carried, signed, through every rotation,
 * every grace replay and every re-mint of the current session (an organisation switch, a renewal
 * after a credential change). It is the RFC 6819 / OAuth 2.0 Security BCP refresh-token family,
 * and what OIDC means by a session id.
 *
 * It is in both tokens (claim `fam`) and in the session's metadata, so a holder of only the id can
 * ask whether the family is still alive ({@see JwtService::isSessionFamilyLive()}).
 */
final class JwtSessionFamily
{
    public const CLAIM    = 'fam';
    public const META_KEY = 'session_family';

    private function __construct() {}
}
