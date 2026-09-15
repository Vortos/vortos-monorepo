<?php

declare(strict_types=1);

namespace Vortos\Migration\Access;

enum DatabaseRoleConformanceStatus: string
{
    /** Every declared role holds exactly its privileges, and nothing else can log in or connect as a superuser. */
    case Conforming = 'conforming';
    /** At least one departure from the model. */
    case Violated = 'violated';
    /** No role model is declared — nothing to hold the database to. */
    case Undeclared = 'undeclared';
    /** The catalog could not be read, or this connection is not to the declared database. */
    case Indeterminate = 'indeterminate';
}
