<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Exception;

final class InvalidPipelineException extends \InvalidArgumentException
{
    public static function emptyStages(): self
    {
        return new self('A pipeline must contain at least one stage.');
    }

    public static function duplicateStageId(string $id): self
    {
        return new self(sprintf('Duplicate stage ID "%s" — stage IDs must be unique within a pipeline.', $id));
    }

    public static function unknownNeed(string $stageId, string $needId): self
    {
        return new self(sprintf(
            'Stage "%s" declares a dependency on "%s", but no stage with that ID exists in the pipeline.',
            $stageId,
            $needId,
        ));
    }

    /**
     * @param list<string> $cycle
     */
    public static function cyclicNeeds(array $cycle): self
    {
        return new self(sprintf(
            'Cyclic stage dependency detected: %s. Stage needs must form a DAG.',
            implode(' → ', $cycle),
        ));
    }
}
