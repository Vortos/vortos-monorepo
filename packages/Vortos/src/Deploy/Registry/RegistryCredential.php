<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry;

/**
 * Sealed base for registry credentials.
 *
 * Only the concrete subclasses in this namespace are valid credential types.
 * Each auth strategy driver calls supports() to reject foreign types at its boundary.
 */
abstract class RegistryCredential {}
