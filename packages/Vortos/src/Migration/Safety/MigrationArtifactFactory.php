<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

use Vortos\Migration\Attribute\AllowFullTableRewrite;
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

        return new MigrationArtifact(
            version: $className,
            className: $className,
            phase: $phase,
            upSql: $upSql,
            downSql: $downSql,
            hasAllowFullTableRewrite: $hasOptOut,
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
    ): MigrationArtifact {
        return new MigrationArtifact(
            version: $version,
            className: null,
            phase: $phase,
            upSql: $upSql,
            downSql: $downSql,
            hasAllowFullTableRewrite: $hasAllowFullTableRewrite,
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
        if (!class_exists($className)) {
            return false;
        }

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            return false;
        }

        return $reflection->getAttributes(AllowFullTableRewrite::class) !== [];
    }

    /** @return list<string> */
    private function extractDownSql(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        try {
            $file = (new \ReflectionClass($className))->getFileName();
        } catch (\ReflectionException) {
            return [];
        }

        if ($file === false || !is_readable($file)) {
            return [];
        }

        $source = file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $downBody = $this->extractMethodBody($source, 'down');
        if ($downBody === null) {
            return [];
        }

        return $this->extractAddSqlFromBody($downBody);
    }

    private function extractMethodBody(string $source, string $method): ?string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{/';

        if (!preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = (int) $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $len = strlen($source);

        for ($i = $start; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function extractAddSqlFromBody(string $body): array
    {
        $sql = [];

        if (preg_match_all(
            '/->addSql\s*\(\s*<<<[\'"]?(\w+)[\'"]?\s*\n(.*?)\n[ \t]*\1[ \t]*[,)]/s',
            $body,
            $m,
        )) {
            foreach ($m[2] as $s) {
                $sql[] = trim($s);
            }
        }

        if (preg_match_all("/->addSql\s*\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $body, $m)) {
            foreach ($m[1] as $s) {
                $sql[] = stripslashes($s);
            }
        }

        if (preg_match_all('/->addSql\s*\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $body, $m)) {
            foreach ($m[1] as $s) {
                $sql[] = stripslashes($s);
            }
        }

        return array_values(array_filter(array_map('trim', $sql)));
    }
}
