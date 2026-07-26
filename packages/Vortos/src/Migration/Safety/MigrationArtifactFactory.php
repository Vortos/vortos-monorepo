<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

use Vortos\Migration\Attribute\AllowFullTableRewrite;
use Vortos\Migration\Attribute\AllowNonIdempotentConcurrent;
use Vortos\Migration\Attribute\DeployPhase;
use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Service\MigrationSqlExtractorInterface;

final class MigrationArtifactFactory implements MigrationArtifactFactoryInterface
{
    public function __construct(
        private readonly MigrationSqlExtractorInterface $extractor,
    ) {}

    public function fromClass(string $className): MigrationArtifact
    {
        $upSql = $this->extractor->extractFromClass($className);
        $downSql = $this->extractDownSql($className);
        $phase = $this->resolvePhase($className);
        $hasOptOut = $this->hasAllowFullTableRewrite($className);
        $hasConcurrentOptOut = $this->hasClassAttribute($className, AllowNonIdempotentConcurrent::class);

        return new MigrationArtifact(
            version: $className,
            className: $className,
            phase: $phase,
            upSql: $upSql,
            downSql: $downSql,
            hasAllowFullTableRewrite: $hasOptOut,
            hasAllowNonIdempotentConcurrent: $hasConcurrentOptOut,
        );
    }

    /**
     * @param list<string> $upSql
     * @param list<string> $downSql
     */
    public function fromRawSql(
        string $version,
        array $upSql,
        array $downSql = [],
        ?MigrationPhase $phase = null,
        bool $hasAllowFullTableRewrite = false,
        bool $hasAllowNonIdempotentConcurrent = false,
    ): MigrationArtifact {
        return new MigrationArtifact(
            version: $version,
            className: null,
            phase: $phase,
            upSql: $upSql,
            downSql: $downSql,
            hasAllowFullTableRewrite: $hasAllowFullTableRewrite,
            hasAllowNonIdempotentConcurrent: $hasAllowNonIdempotentConcurrent,
        );
    }

    private function resolvePhase(string $className): ?MigrationPhase
    {
        if (!class_exists($className)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            return null;
        }

        $attributes = $reflection->getAttributes(DeployPhase::class);

        if ($attributes === []) {
            return null;
        }

        /** @var DeployPhase $attr */
        $attr = $attributes[0]->newInstance();

        return $attr->phase;
    }

    private function hasAllowFullTableRewrite(string $className): bool
    {
        return $this->hasClassAttribute($className, AllowFullTableRewrite::class);
    }

    /** @param class-string $attribute */
    private function hasClassAttribute(string $className, string $attribute): bool
    {
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            return false;
        }

        return $reflection->getAttributes($attribute) !== [];
    }

    /**
     * down() SQL comes from the same extractor as up(), so both halves of a migration are
     * parsed by one implementation. This used to be a private regex copy, which meant a fix
     * to the extractor silently left down() analysis broken.
     *
     * @return list<string>
     */
    private function extractDownSql(string $className): array
    {
        return array_values($this->extractor->extractFromClassMethod($className, 'down'));
    }
}
