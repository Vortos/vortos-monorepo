<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

interface MigrationSqlExtractorInterface
{
    /** @return string[] */
    public function extractFromClass(string $className): array;

    /**
     * SQL from an arbitrary method — `down()` in particular, whose statements must never
     * be analysed as forward SQL.
     *
     * @return string[]
     */
    public function extractFromClassMethod(string $className, string $method): array;

    /** @return string[] */
    public function extractFromSource(string $source): array;
}
