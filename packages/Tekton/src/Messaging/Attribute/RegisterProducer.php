<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Attribute;

use Attribute;

/**
 * Marks a method inside a MessagingConfig class as a producer definition provider.
 *
 * The method must return an instance of AbstractProducerDefinition.
 * The referenced transport name must match a registered transport or
 * the compiler pass will throw at compile time.
 * Discovered and registered by MessagingConfigCompilerPass at container compile time.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RegisterProducer
{

}