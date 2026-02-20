<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Attribute;

use Attribute;

/**
 * Marks a method inside a MessagingConfig class as a transport definition provider.
 *
 * The method must return an instance of AbstractTransportDefinition.
 * The transport name is taken from the definition's getName() method.
 * Discovered and registered by MessagingConfigCompilerPass at container compile time.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RegisterTransport 
{
}
