<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

enum PgBackRestRepositoryStatus: string
{
    case Healthy = 'healthy';
    /** The repository cannot restore what it should: page. */
    case Failing = 'failing';
    /** This installation does not use pgBackRest. */
    case Undeclared = 'undeclared';
}
