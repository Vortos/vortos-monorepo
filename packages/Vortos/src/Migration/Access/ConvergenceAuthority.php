<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

/**
 * Who can apply a part of the role model — which decides where and how often it runs.
 *
 * Superuser: creating roles, setting REPLICATION/BYPASSRLS, predefined-role memberships, catalog grants and moving
 * ownership away from the bootstrap superuser. Rare, deliberate, run by an operator through the server's local
 * socket; the deploy never holds a superuser credential.
 *
 * Owner: every grant on objects the owner owns, and its default privileges. Idempotent and run by the deploy on
 * every release, so a table a migration adds is granted before the release that uses it serves traffic.
 */
enum ConvergenceAuthority: string
{
    case Superuser = 'superuser';
    case Owner = 'owner';
}
