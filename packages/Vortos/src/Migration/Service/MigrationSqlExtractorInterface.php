<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

interface MigrationSqlExtractorInterface
{
    /** @return string[] */
    public function extractFromClass(string $className): array;

    /** @return string[] */
    public function extractFromSource(string $source): array;
}
