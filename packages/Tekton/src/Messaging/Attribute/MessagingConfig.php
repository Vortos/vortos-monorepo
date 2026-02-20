<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Attribute;

use Attribute;

/**
 * Marks a class as a messaging configuration provider for a bounded context.
 *
 * Classes marked with this attribute are discovered by the compiler pass and
 * inspected for methods annotated with RegisterTransport, RegisterProducer,
 * and RegisterConsumer. Each such method is called to retrieve its definition
 * and register it in the appropriate registry.
 *
 * One MessagingConfig class per bounded context is the recommended pattern.
 * The class must have no constructor dependencies — it is instantiated by
 * the compiler pass via reflection.
 *
 * Example:
 *   #[MessagingConfig]
 *   final class OrderMessagingConfig
 *   {
 *       #[RegisterTransport]
 *       public function ordersPlacedTransport(): KafkaTransportDefinition { ... }
 *   }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class MessagingConfig
{

}