<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Exception;

/** A NOT NULL constraint was violated — a required column received NULL. */
class NotNullConstraintException extends ConstraintViolationException {}
