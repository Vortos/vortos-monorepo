<?php

declare(strict_types=1);

namespace Vortos\Deploy\State;

use Vortos\Deploy\Plan\StepAction;

final readonly class StepOutcome
{
    public function __construct(
        public int $stepIndex,
        public StepAction $action,
        public StepStatus $status,
        public string $result = '',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'step_index' => $this->stepIndex,
            'action' => $this->action->value,
            'status' => $this->status->value,
            'result' => $this->result,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            stepIndex: (int) $data['step_index'],
            action: StepAction::from((string) $data['action']),
            status: StepStatus::from((string) $data['status']),
            result: (string) ($data['result'] ?? ''),
        );
    }
}
