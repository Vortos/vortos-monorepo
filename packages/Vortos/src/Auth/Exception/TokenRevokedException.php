<?php

declare(strict_types=1);

namespace Vortos\Auth\Exception;

/**
 * The refresh token's session was deliberately ended: signed out, revoked, superseded, or past an
 * age it may not exceed. Ends this session only; the user's other sessions are untouched.
 */
class TokenRevokedException extends AuthenticationException {}
