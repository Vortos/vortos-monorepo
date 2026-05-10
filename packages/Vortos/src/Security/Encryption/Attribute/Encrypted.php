<?php

declare(strict_types=1);

namespace Vortos\Security\Encryption\Attribute;

use Attribute;

/**
 * Marks a model property for automatic field-level encryption.
 *
 * The EncryptionCompilerPass discovers all properties carrying this attribute
 * at compile time and stores the class→field map as a container parameter.
 *
 * The application code is responsible for calling EncryptionService::encrypt()
 * before persistence and decrypt() on retrieval. Use a domain event or a
 * repository decorator to automate this.
 *
 * @param string $context Encryption context (defaults to the property name).
 *                        Use a stable, specific value — changing it prevents
 *                        decryption of existing ciphertexts.
 *
 * Example:
 *
 *   final class UserProfile
 *   {
 *       #[Encrypted(context: 'user.ssn')]
 *       public string $socialSecurityNumber;
 *
 *       #[Encrypted(context: 'user.phone')]
 *       public string $phoneNumber;
 *   }
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Encrypted
{
    public function __construct(
        public readonly string $context = '',
    ) {}
}
