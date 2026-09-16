<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;

/**
 * One bucket lifecycle rule: what happens to objects under a prefix as they age.
 *
 * A rule may expire objects, transition them to a cheaper storage class, or both. It always has a
 * non-empty prefix: a bucket-wide rule would also sweep temporary uploads, public assets and anything
 * another team keeps in the bucket, which is never what a single declaration intends.
 *
 * Rules are compared as models, not as the raw arrays the provider returns. Providers echo rules back
 * with their own key order, defaults and legacy fields, so a byte comparison reports drift that is not
 * there and `plan` would never converge.
 */
final class LifecycleRule
{
    /** S3 caps rule IDs at 255 characters; R2 follows the S3 API. */
    private const MAX_ID_LENGTH = 255;

    /** @var list<LifecycleTransition> */
    private readonly array $transitions;

    private readonly string $prefix;

    /** @param list<LifecycleTransition> $transitions */
    public function __construct(
        private readonly string $id,
        string $prefix,
        private readonly ?int $expirationDays = null,
        array $transitions = [],
        private readonly LifecycleRuleStatus $status = LifecycleRuleStatus::Enabled,
    ) {
        if ($id === '') {
            throw new ObjectStoreConfigurationException('Lifecycle rule ID cannot be empty.');
        }

        if (strlen($id) > self::MAX_ID_LENGTH) {
            throw new ObjectStoreConfigurationException(sprintf('Lifecycle rule ID "%s" exceeds %d characters.', $id, self::MAX_ID_LENGTH));
        }

        if ($expirationDays !== null && $expirationDays < 1) {
            throw new ObjectStoreConfigurationException('Lifecycle expiration must be at least one day.');
        }

        if ($expirationDays === null && $transitions === []) {
            throw new ObjectStoreConfigurationException(sprintf('Lifecycle rule "%s" must expire or transition objects.', $id));
        }

        $seen = [];
        foreach ($transitions as $transition) {
            if (isset($seen[$transition->storageClass()->value])) {
                throw new ObjectStoreConfigurationException(sprintf(
                    'Lifecycle rule "%s" transitions to %s more than once.',
                    $id,
                    $transition->storageClass()->value,
                ));
            }
            $seen[$transition->storageClass()->value] = true;

            // The provider resolves an expiration and a transition that land within the same day in
            // favour of the expiration, so the transition would bill a class-change operation (and,
            // on R2, a 30-day minimum storage charge) for an object that is deleted anyway.
            if ($expirationDays !== null && $transition->days() >= $expirationDays) {
                throw new ObjectStoreConfigurationException(sprintf(
                    'Lifecycle rule "%s" transitions to %s after %d days but expires after %d; the transition would never take effect.',
                    $id,
                    $transition->storageClass()->value,
                    $transition->days(),
                    $expirationDays,
                ));
            }
        }

        usort($transitions, static fn(LifecycleTransition $a, LifecycleTransition $b): int => $a->days() <=> $b->days());

        $this->prefix = self::normalizePrefix($prefix);
        $this->transitions = $transitions;
    }

    public static function temporaryUploadExpiry(
        string $ruleId,
        string $temporaryPrefix,
        int $orphanTtlSeconds,
        bool $roundUpMinimumLifecycleDay = false,
    ): self {
        if ($orphanTtlSeconds < 86400 && !$roundUpMinimumLifecycleDay) {
            throw new ObjectStoreConfigurationException(
                'Object-store lifecycle expiration uses day-level S3 semantics. Set orphan_ttl_seconds to at least 86400 or enable round_up_minimum_lifecycle_day.',
            );
        }

        return new self($ruleId, $temporaryPrefix, max(1, (int) ceil($orphanTtlSeconds / 86400)));
    }

    public static function expireAfter(string $ruleId, string $prefix, int $days): self
    {
        return new self($ruleId, $prefix, $days);
    }

    public static function transitionAfter(string $ruleId, string $prefix, int $days, ObjectStorageClass $storageClass): self
    {
        return new self($ruleId, $prefix, null, [new LifecycleTransition($days, $storageClass)]);
    }

    /**
     * Reads a rule as the provider returned it.
     *
     * Returns null for anything this model cannot represent exactly — date-based expiration, tag
     * filters, noncurrent-version actions, archive classes, a bucket-wide prefix. For a managed rule
     * ID that makes the plan an Update, which rewrites the rule to the declared shape: the safe
     * direction, since the alternative is silently leaving an unknown action in force.
     *
     * @param array<string, mixed> $rule
     */
    public static function fromS3Rule(array $rule): ?self
    {
        $allowed = ['ID', 'Status', 'Filter', 'Prefix', 'Expiration', 'Transitions'];
        if (array_diff(array_keys($rule), $allowed) !== []) {
            return null;
        }

        $id = $rule['ID'] ?? null;
        $status = is_string($rule['Status'] ?? null) ? LifecycleRuleStatus::tryFrom($rule['Status']) : null;
        if (!is_string($id) || $status === null) {
            return null;
        }

        $filter = $rule['Filter'] ?? null;
        if ($filter !== null && (!is_array($filter) || array_diff(array_keys($filter), ['Prefix']) !== [])) {
            return null;
        }
        $prefix = $filter['Prefix'] ?? ($rule['Prefix'] ?? null);
        if (!is_string($prefix)) {
            return null;
        }

        $expirationDays = null;
        if (isset($rule['Expiration'])) {
            $expiration = $rule['Expiration'];
            if (!is_array($expiration) || array_keys($expiration) !== ['Days'] || !is_int($expiration['Days'])) {
                return null;
            }
            $expirationDays = $expiration['Days'];
        }

        $transitions = [];
        foreach (is_array($rule['Transitions'] ?? null) ? $rule['Transitions'] : [] as $transition) {
            if (!is_array($transition) || array_diff(array_keys($transition), ['Days', 'StorageClass']) !== []) {
                return null;
            }
            $class = is_string($transition['StorageClass'] ?? null) ? ObjectStorageClass::tryFrom($transition['StorageClass']) : null;
            if ($class === null || !is_int($transition['Days'] ?? null)) {
                return null;
            }
            $transitions[] = new LifecycleTransition($transition['Days'], $class);
        }
        if (isset($rule['Transitions']) && !is_array($rule['Transitions'])) {
            return null;
        }

        try {
            return new self($id, $prefix, $expirationDays, $transitions, $status);
        } catch (ObjectStoreConfigurationException) {
            return null;
        }
    }

    /**
     * The inverse of toConfigArray(): how a declared rule crosses the container boundary, since a
     * compiled container can only carry scalars and arrays.
     *
     * @param array{id: string, prefix: string, expiration_days?: int|null, transitions?: list<array{days: int, storage_class: string}>, status?: string} $config
     */
    public static function fromConfigArray(array $config): self
    {
        $transitions = [];
        foreach ($config['transitions'] ?? [] as $transition) {
            $transitions[] = new LifecycleTransition(
                (int) $transition['days'],
                ObjectStorageClass::tryFrom((string) $transition['storage_class'])
                    ?? throw new ObjectStoreConfigurationException(sprintf('Unsupported lifecycle storage class "%s".', $transition['storage_class'])),
            );
        }

        return new self(
            (string) $config['id'],
            (string) $config['prefix'],
            isset($config['expiration_days']) ? (int) $config['expiration_days'] : null,
            $transitions,
            LifecycleRuleStatus::tryFrom((string) ($config['status'] ?? LifecycleRuleStatus::Enabled->value))
                ?? throw new ObjectStoreConfigurationException(sprintf('Unsupported lifecycle rule status "%s".', $config['status'] ?? '')),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function expirationDays(): ?int
    {
        return $this->expirationDays;
    }

    /** @return list<LifecycleTransition> */
    public function transitions(): array
    {
        return $this->transitions;
    }

    public function status(): LifecycleRuleStatus
    {
        return $this->status;
    }

    public function equals(self $other): bool
    {
        if ($this->id !== $other->id
            || $this->prefix !== $other->prefix
            || $this->status !== $other->status
            || $this->expirationDays !== $other->expirationDays
            || count($this->transitions) !== count($other->transitions)) {
            return false;
        }

        foreach ($this->transitions as $i => $transition) {
            if (!$transition->equals($other->transitions[$i])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function toS3Rule(): array
    {
        $rule = [
            'ID' => $this->id,
            'Status' => $this->status->value,
            'Filter' => ['Prefix' => $this->prefix],
        ];

        if ($this->expirationDays !== null) {
            $rule['Expiration'] = ['Days' => $this->expirationDays];
        }

        if ($this->transitions !== []) {
            $rule['Transitions'] = array_map(static fn(LifecycleTransition $t): array => $t->toS3(), $this->transitions);
        }

        return $rule;
    }

    /** @return array{id: string, prefix: string, expiration_days: int|null, transitions: list<array{days: int, storage_class: string}>, status: string} */
    public function toConfigArray(): array
    {
        return [
            'id' => $this->id,
            'prefix' => $this->prefix,
            'expiration_days' => $this->expirationDays,
            'transitions' => array_map(
                static fn(LifecycleTransition $t): array => ['days' => $t->days(), 'storage_class' => $t->storageClass()->value],
                $this->transitions,
            ),
            'status' => $this->status->value,
        ];
    }

    private static function normalizePrefix(string $prefix): string
    {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            throw new ObjectStoreConfigurationException('Lifecycle rule prefix cannot be empty; a bucket-wide rule would also act on temporary uploads and every other key in the bucket.');
        }

        return $prefix . '/';
    }
}
