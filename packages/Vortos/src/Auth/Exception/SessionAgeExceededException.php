<?php

declare(strict_types=1);

namespace Vortos\Auth\Exception;

/**
 * The session is older than the caller allows (an absolute session timeout, OWASP Session
 * Management), counted from the original sign-in (`auth_time`), not from the last refresh.
 * A subclass of {@see TokenRevokedException}, so a caller that does not care why still ends
 * only this session.
 */
final class SessionAgeExceededException extends TokenRevokedException {}
