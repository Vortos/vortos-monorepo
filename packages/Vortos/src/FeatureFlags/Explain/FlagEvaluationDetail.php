<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Explain;

use Vortos\FeatureFlags\FlagValue;

/**
 * The full trace of a single flag evaluation: value, variant, matched rule, bucket,
 * and human-readable reason. Opt-in path only — the hot `evaluate()` never allocates this.
 */
final class FlagEvaluationDetail
{
    public function __construct(
        public readonly string $flagName,
        public readonly FlagValue $value,
        public readonly string $variant,
        public readonly EvaluationReason $reason,
        public readonly ?int $matchedRuleIndex = null,
        public readonly ?string $matchedRuleDescription = null,
        public readonly ?int $bucket = null,
        public readonly ?string $bucketBy = null,
        public readonly ?string $prerequisiteFlag = null,
        public readonly ?string $errorMessage = null,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $result = [
            'flag'    => $this->flagName,
            'value'   => $this->value->raw(),
            'variant' => $this->variant,
            'reason'  => $this->reason->value,
        ];

        if ($this->matchedRuleIndex !== null) {
            $result['matched_rule_index'] = $this->matchedRuleIndex;
        }
        if ($this->matchedRuleDescription !== null) {
            $result['matched_rule_description'] = $this->matchedRuleDescription;
        }
        if ($this->bucket !== null) {
            $result['bucket'] = $this->bucket;
        }
        if ($this->bucketBy !== null) {
            $result['bucket_by'] = $this->bucketBy;
        }
        if ($this->prerequisiteFlag !== null) {
            $result['prerequisite_flag'] = $this->prerequisiteFlag;
        }
        if ($this->errorMessage !== null) {
            $result['error_message'] = $this->errorMessage;
        }

        return $result;
    }
}
