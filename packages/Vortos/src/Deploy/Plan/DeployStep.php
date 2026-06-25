<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

use Vortos\Secrets\Preflight\SecretReference;

final readonly class DeployStep
{
    /**
     * @param array<string, scalar|null>  $params
     * @param list<SecretReference>        $secretReferences
     */
    public function __construct(
        public StepAction $action,
        public string $description,
        public array $params = [],
        public array $secretReferences = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'action' => $this->action->value,
            'description' => $this->description,
        ];

        if ($this->params !== []) {
            $params = $this->params;
            ksort($params);
            $data['params'] = $params;
        }

        if ($this->secretReferences !== []) {
            $data['secret_references'] = array_map(
                static fn (SecretReference $ref): string => $ref->key->value(),
                $this->secretReferences,
            );
        }

        return $data;
    }
}
