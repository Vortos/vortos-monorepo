<?php

declare(strict_types=1);

namespace Vortos\Iac\Terraform;

use Vortos\Iac\Exception\IacException;

/**
 * A reference to another Terraform resource attribute,
 * e.g. `confluent_kafka_cluster.main.id`. Every dot-separated segment is
 * validated as a strict identifier — a reference can never carry an
 * arbitrary expression.
 */
final readonly class TfReference implements TfValueInterface
{
    private const SEGMENT_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]*$/';

    /** @var list<string> */
    public array $segments;

    public function __construct(string $reference)
    {
        $segments = explode('.', $reference);

        if (count($segments) < 2) {
            throw new IacException(sprintf(
                "Invalid Terraform reference '%s' — expected at least 'type.name'.",
                $reference,
            ));
        }

        foreach ($segments as $segment) {
            if (!preg_match(self::SEGMENT_PATTERN, $segment)) {
                throw new IacException(sprintf(
                    "Invalid segment '%s' in Terraform reference '%s'.",
                    $segment,
                    $reference,
                ));
            }
        }

        $this->segments = $segments;
    }

    public function toJsonValue(): string
    {
        return '${' . implode('.', $this->segments) . '}';
    }
}
