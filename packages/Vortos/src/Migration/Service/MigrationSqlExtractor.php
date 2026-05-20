<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

/**
 * Extracts raw SQL strings from addSql() calls in a Doctrine migration class file.
 *
 * Handles three string forms:
 *   $this->addSql('...')          single-quoted
 *   $this->addSql("...")          double-quoted
 *   $this->addSql(<<<'SQL' ... SQL) heredoc / nowdoc
 *
 * Pure file I/O — no DB connection, no Doctrine internals.
 */
final class MigrationSqlExtractor implements MigrationSqlExtractorInterface
{
    /**
     * @return string[]  SQL strings found in the migration's up() method (best-effort)
     */
    public function extractFromClass(string $className): array
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

        return $source !== false ? $this->extractFromSource($source) : [];
    }

    /**
     * @return string[]
     */
    public function extractFromSource(string $source): array
    {
        $sql = [];

        // Heredoc / nowdoc: ->addSql(<<<'SQL' ... SQL) or ->addSql(<<<SQL ... SQL)
        if (preg_match_all(
            '/->addSql\s*\(\s*<<<[\'"]?(\w+)[\'"]?\s*\n(.*?)\n[ \t]*\1[ \t]*[,)]/s',
            $source,
            $m,
        )) {
            foreach ($m[2] as $s) {
                $sql[] = trim($s);
            }
        }

        // Single-quoted strings: ->addSql('...')
        if (preg_match_all("/->addSql\s*\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $m)) {
            foreach ($m[1] as $s) {
                $sql[] = stripslashes($s);
            }
        }

        // Double-quoted strings: ->addSql("...")
        if (preg_match_all('/->addSql\s*\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $source, $m)) {
            foreach ($m[1] as $s) {
                $sql[] = stripslashes($s);
            }
        }

        return array_values(array_filter(array_map('trim', $sql)));
    }
}
