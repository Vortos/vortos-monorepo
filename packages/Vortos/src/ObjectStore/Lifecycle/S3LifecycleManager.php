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
    /** @param string[] $observabilityDisabledSections */
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

    public function planTemporaryUploadExpiry(): LifecyclePlan
    {
        $this->assertUsable();

        return $this->observe('lifecycle_plan', function (): LifecyclePlan {
            if (!$this->manageTemporaryUploads) {
                $current = $this->current();
                return new LifecyclePlan($current, $current, LifecyclePlanChange::None, $this->managedRuleId);
            }

            $current = $this->current();
            $rule = LifecycleRule::temporaryUploadExpiry(
                $this->managedRuleId,
                $this->temporaryPrefix,
                $this->orphanTtlSeconds,
                $this->roundUpMinimumLifecycleDay,
            );
            $desired = $current->withRule($rule);
            $existing = $current->rule($this->managedRuleId);

            return new LifecyclePlan(
                $current,
                $desired,
                $current->equals($desired) ? LifecyclePlanChange::None : ($existing === null ? LifecyclePlanChange::Create : LifecyclePlanChange::Update),
                $this->managedRuleId,
            );
        });
    }

    public function planRemoveManagedRule(): LifecyclePlan
    {
        $this->assertUsable();

        return $this->observe('lifecycle_remove', function (): LifecyclePlan {
            $current = $this->current();
            $desired = $current->withoutRule($this->managedRuleId);

            return new LifecyclePlan(
                $current,
                $desired,
                $current->equals($desired) ? LifecyclePlanChange::None : LifecyclePlanChange::Remove,
                $this->managedRuleId,
            );
        });
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
