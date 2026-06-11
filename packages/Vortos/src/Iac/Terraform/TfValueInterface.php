<?php

declare(strict_types=1);

namespace Vortos\Iac\Terraform;

/**
 * A value that can appear in a Terraform JSON document.
 *
 * This closed set of implementations (TfLiteral, TfVariable, TfReference) is
 * the ONLY way data enters a document. Terraform's `${...}` expression syntax
 * is emitted exclusively by TfVariable/TfReference, whose names are validated
 * against strict identifier rules at construction — user data can never
 * smuggle an expression into generated Terraform.
 */
interface TfValueInterface
{
    /** The JSON-encodable representation, expression-escaped where needed. */
    public function toJsonValue(): mixed;
}
