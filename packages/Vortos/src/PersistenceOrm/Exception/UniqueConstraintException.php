<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Exception;

/** A UNIQUE constraint was violated — the value already exists in the column. */
class UniqueConstraintException extends ConstraintViolationException {}
