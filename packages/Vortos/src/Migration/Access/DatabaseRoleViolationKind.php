<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

/** Each way the live database can depart from the declared role model. Names and privilege words only, never data. */
enum DatabaseRoleViolationKind: string
{
    /** A declared role does not exist. */
    case RoleMissing = 'role_missing';
    /** A role attribute (SUPERUSER, BYPASSRLS, REPLICATION, CREATEROLE, CREATEDB, LOGIN) differs from the model. */
    case RoleAttribute = 'role_attribute';
    /** A declared role is a member of a role the model does not grant it, or lacks one it does. */
    case RoleMembership = 'role_membership';
    /** A role the model does not declare can log in, or holds SUPERUSER. */
    case UndeclaredRole = 'undeclared_role';
    /** The database, a governed schema or an object in one is owned by someone other than the owner role. */
    case Ownership = 'ownership';
    /** A role (or PUBLIC) holds a privilege the model does not give it. */
    case ExcessPrivilege = 'excess_privilege';
    /** A declared role lacks a privilege the model requires. */
    case MissingPrivilege = 'missing_privilege';
    /** The owner's default privileges would not grant the runtime role access to objects created later. */
    case DefaultPrivileges = 'default_privileges';
    /** A client is connected over the network as a superuser. */
    case SuperuserClientConnection = 'superuser_client_connection';
}
