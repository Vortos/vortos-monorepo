<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\Storage;

use Vortos\FeatureFlags\Guardrail\GuardrailPolicy;

interface GuardrailPolicyStorageInterface
{
    public function save(GuardrailPolicy $policy): void;

    public function findById(string $id): ?GuardrailPolicy;

    /** @return GuardrailPolicy[] */
    public function findEnabled(string $projectId, string $environment): array;

    /** @return GuardrailPolicy[] */
    public function findDueForEvaluation(\DateTimeImmutable $before, int $limit): array;

    public function delete(string $id): void;
}
