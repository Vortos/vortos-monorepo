<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Exception;

/** A FOREIGN KEY constraint was violated — referenced row does not exist. */
class ForeignKeyConstraintException extends ConstraintViolationException {}
