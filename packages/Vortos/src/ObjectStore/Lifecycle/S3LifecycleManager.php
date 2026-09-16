<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\ObjectStore\Capability\ObjectStoreProviderCapability;
use Vortos\ObjectStore\Capability\ProviderCapabilities;
use Vortos\ObjectStore\Config\ObjectStoreObservabilitySection;
use Vortos\ObjectStore\Contract\LifecycleManagerInterface;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;
use Vortos\ObjectStore\Exception\ObjectStoreException;
use Vortos\Tracing\Contract\TracingInterface;

final class S3LifecycleManager implements LifecycleManagerInterface
{
    /**
     * @param list<array<string, mixed>> $declaredRules LifecycleRule::toConfigArray() shapes
     * @param string[] $observabilityDisabledSections
     */
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly ProviderCapabilities $capabilities,
        private readonly LoggerInterface $logger,
        private readonly string $temporaryPrefix,
        private readonly int $orphanTtlSeconds,
        private readonly string $managedRuleId,
        private readonly bool $roundUpMinimumLifecycleDay = false,
        private readonly bool $manageTemporaryUploads = true,
        private readonly array $declaredRules = [],
        private readonly string $managedRuleIdPrefix = 'vortos-',
        private readonly ?TracingInterface $tracer = null,
        private readonly ?MetricsInterface $metrics = null,
        private readonly array $observabilityDisabledSections = [],
    ) {}

    public function current(): LifecycleConfiguration
    {
        $this->assertUsable();

        return $this->observe('lifecycle_current', function (): LifecycleConfiguration {
            try {
                return LifecycleConfiguration::fromS3Result($this->client->getBucketLifecycleConfiguration([
                    'Bucket' => $this->bucket,
                ])->toArray());
            } catch (AwsException $e) {
                if (in_array($e->getAwsErrorCode(), ['NoSuchLifecycleConfiguration', 'NoSuchLifecycle', 'NotFound', '404'], true)) {
                    return LifecycleConfiguration::empty();
                }

                throw new ObjectStoreException(
                    sprintf('Failed to read object-store lifecycle configuration: %s', $e->getAwsErrorMessage() ?? $e->getMessage()),
                    previous: $e,
                );
            }
        });
    }

    public function planManagedRules(): LifecyclePlan
    {
        $this->assertUsable();

        return $this->observe('lifecycle_plan', function (): LifecyclePlan {
            $managed = $this->managedRules();
            $this->assertProviderAccepts($managed);
            $current = $this->current();

            $desiredRules = [];
            $changes = [];

            foreach ($current->rules() as $existing) {
                $id = (string) ($existing['ID'] ?? '');
                if (!$this->owns($managed, $id)) {
                    $desiredRules[] = $existing;
                    continue;
                }

                if ($managed->rule($id) === null) {
                    $changes[] = new LifecycleRuleChange($id, LifecyclePlanChange::Remove, $existing, null);
                }
            }

            foreach ($managed->rules() as $rule) {
                $existing = $current->rule($rule->id());
                $parsed = $existing === null ? null : LifecycleRule::fromS3Rule($existing);

                $change = match (true) {
                    $existing === null => LifecyclePlanChange::Create,
                    $parsed !== null && $parsed->equals($rule) => LifecyclePlanChange::None,
                    default => LifecyclePlanChange::Update,
                };

                $desiredRules[] = $rule->toS3Rule();
                $changes[] = new LifecycleRuleChange($rule->id(), $change, $existing, $rule->toS3Rule());
            }

            // The declared set alone is capped in ManagedLifecycleRules; this catches declared plus
            // console-created rules together overflowing the bucket's limit, before the provider does.
            if (count($desiredRules) > ManagedLifecycleRules::MAX_RULES_PER_BUCKET) {
                throw new ObjectStoreConfigurationException(sprintf(
                    'Bucket "%s" would hold %d lifecycle rules; providers accept at most %d.',
                    $this->bucket,
                    count($desiredRules),
                    ManagedLifecycleRules::MAX_RULES_PER_BUCKET,
                ));
            }

            return new LifecyclePlan($current, new LifecycleConfiguration($desiredRules), $changes);
        });
    }

    public function planRemoveManagedRules(): LifecyclePlan
    {
        $this->assertUsable();

        return $this->observe('lifecycle_remove', function (): LifecyclePlan {
            $managed = $this->managedRules();
            $current = $this->current();

            $desiredRules = [];
            $changes = [];
            foreach ($current->rules() as $existing) {
                $id = (string) ($existing['ID'] ?? '');
                if ($this->owns($managed, $id)) {
                    $changes[] = new LifecycleRuleChange($id, LifecyclePlanChange::Remove, $existing, null);
                    continue;
                }
                $desiredRules[] = $existing;
            }

            return new LifecyclePlan($current, new LifecycleConfiguration($desiredRules), $changes);
        });
    }

    /**
     * Built on every call from constructor config — never cached on the service, which lives for the
     * whole worker process.
     */
    private function managedRules(): ManagedLifecycleRules
    {
        $rules = array_map(
            static fn(array $config): LifecycleRule => LifecycleRule::fromConfigArray($config),
            $this->declaredRules,
        );

        if ($this->manageTemporaryUploads) {
            array_unshift($rules, LifecycleRule::temporaryUploadExpiry(
                $this->managedRuleId,
                $this->temporaryPrefix,
                $this->orphanTtlSeconds,
                $this->roundUpMinimumLifecycleDay,
            ));
        }

        return new ManagedLifecycleRules($this->managedRuleIdPrefix, $rules);
    }

    /**
     * With temporary-upload management switched off, the temporary-upload rule ID belongs to whoever
     * switched it off: removing it as "undeclared" would delete the orphan cleanup they now run
     * themselves.
     */
    private function owns(ManagedLifecycleRules $managed, string $ruleId): bool
    {
        if (!$this->manageTemporaryUploads && $ruleId === $this->managedRuleId) {
            return false;
        }

        return $managed->owns($ruleId);
    }

    private function assertProviderAccepts(ManagedLifecycleRules $managed): void
    {
        if (!$managed->hasTransitions()) {
            return;
        }

        $this->capabilities->assertSupported(ObjectStoreProviderCapability::LifecycleStorageClassTransition);

        foreach ($managed->rules() as $rule) {
            foreach ($rule->transitions() as $transition) {
                $minimum = $this->capabilities->minimumTransitionDays($transition->storageClass());
                if ($transition->days() < $minimum) {
                    throw new ObjectStoreConfigurationException(sprintf(
                        'Lifecycle rule "%s" transitions to %s after %d days; provider "%s" requires at least %d.',
                        $rule->id(),
                        $transition->storageClass()->value,
                        $transition->days(),
                        $this->capabilities->provider(),
                        $minimum,
                    ));
                }
            }
        }
    }

    public function apply(LifecyclePlan $plan): LifecycleConfiguration
    {
        $this->assertUsable();

        return $this->observe('lifecycle_apply', function () use ($plan): LifecycleConfiguration {
            if (!$plan->hasChanges()) {
                return $plan->desired();
            }

            try {
                $this->client->putBucketLifecycleConfiguration([
                    'Bucket' => $this->bucket,
                    'LifecycleConfiguration' => $plan->desired()->toS3LifecycleConfiguration(),
                ]);
            } catch (AwsException $e) {
                throw new ObjectStoreException(
                    sprintf('Failed to apply object-store lifecycle configuration: %s', $e->getAwsErrorMessage() ?? $e->getMessage()),
                    previous: $e,
                );
            }

            return $plan->desired();
        });
    }

    private function assertUsable(): void
    {
        if ($this->bucket === '') {
            throw new ObjectStoreConfigurationException('Object-store lifecycle management requires a configured bucket name.');
        }

        $this->capabilities->assertSupported(ObjectStoreProviderCapability::LifecycleConfiguration);
        $this->capabilities->assertSupported(ObjectStoreProviderCapability::LifecyclePrefixExpiration);
    }

    /** @template T */
    private function observe(string $operation, callable $callback): mixed
    {
        $disabled = in_array(ObjectStoreObservabilitySection::Lifecycle->value, $this->observabilityDisabledSections, true);
        $start = hrtime(true);
        $span = null;

        if (!$disabled) {
            $this->logger->info('object_store.lifecycle.started', ['operation' => $operation, 'bucket' => $this->bucket]);
            $span = $this->tracer?->startSpan('object_store.' . $operation, [
                'object_store.lifecycle.operation' => $operation,
            ]);
        }

        try {
            $result = $callback();
            if (!$disabled) {
                $span?->setStatus('ok');
                $this->metrics?->counter('vortos_object_store_lifecycle_operations_total', [
                    'operation' => $operation,
                    'status' => 'success',
                ])->increment();
                $this->logger->info('object_store.lifecycle.succeeded', ['operation' => $operation, 'bucket' => $this->bucket]);
            }

            return $result;
        } catch (\Throwable $e) {
            if (!$disabled) {
                $span?->recordException($e);
                $span?->setStatus('error');
                $this->metrics?->counter('vortos_object_store_lifecycle_operations_total', [
                    'operation' => $operation,
                    'status' => 'failure',
                ])->increment();
                $this->logger->warning('object_store.lifecycle.failed', [
                    'operation' => $operation,
                    'bucket' => $this->bucket,
                    'error' => $e->getMessage(),
                ]);
            }

            throw $e;
        } finally {
            if (!$disabled) {
                $this->metrics?->histogram('vortos_object_store_lifecycle_operation_duration_ms', [
                    'operation' => $operation,
                ])->observe(round((hrtime(true) - $start) / 1_000_000, 2));
                $span?->end();
            }
        }
    }
}
