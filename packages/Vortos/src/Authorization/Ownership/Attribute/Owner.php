<?php

declare(strict_types=1);

namespace Vortos\Authorization\Ownership\Attribute;

/**
 * Marks the property or zero-argument getter that yields a resource's owner id.
 *
 * Lets {@see \Vortos\Authorization\Context\AuthorizationContext::owns()} determine
 * ownership without coupling the domain entity to a framework interface — the
 * attribute is passive metadata, read reflectively, not a behavioral contract.
 *
 *   final class Draft
 *   {
 *       public function __construct(#[Owner] private string $authorId) {}
 *   }
 *
 *   // or on a getter:
 *   #[Owner]
 *   public function ownerId(): string { return $this->authorId; }
 *
 * For resources you cannot annotate (DTOs, third-party types), register an
 * {@see \Vortos\Authorization\Ownership\Contract\OwnerResolverInterface} instead.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class Owner
{
}
