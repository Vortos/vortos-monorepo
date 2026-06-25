<?php

declare(strict_types=1);

namespace Vortos\Iac\Exception;

use Vortos\Iac\Lifecycle\Policy\PolicyResult;

final class PolicyViolationException extends IacException
{
    public function __construct(
        string $message,
        public readonly PolicyResult $result,
    ) {
        parent::__construct($message);
    }

    public static function fromResult(PolicyResult $result): self
    {
        $lines = [];
        foreach ($result->violations as $v) {
            $lines[] = sprintf('  [%s] %s — %s', $v->ruleId, $v->address, $v->message);
        }

        return new self(
            sprintf(
                "Plan blocked by %d policy violation(s):\n%s",
                count($result->violations),
                implode("\n", $lines),
            ),
            $result,
        );
    }
}
