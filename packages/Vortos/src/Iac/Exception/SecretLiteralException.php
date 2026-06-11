<?php

declare(strict_types=1);

namespace Vortos\Iac\Exception;

/**
 * A literal value was assigned to an attribute whose name suggests it holds
 * a secret. Secrets must flow through Env references (which export as
 * sensitive Terraform variables), never as literals baked into .tf.json.
 */
final class SecretLiteralException extends IacException
{
}
