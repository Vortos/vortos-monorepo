<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Exception;

/** A database constraint was violated. Parent of all constraint subtypes. */
class ConstraintViolationException extends PersistenceException {}
