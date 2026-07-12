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

        if ($source === false) {
            return [];
        }

        // Isolate the up() method body before extracting addSql() calls. Without this the
        // regexes match ->addSql() anywhere in the file — including down() — so a migration's
        // rollback SQL would be analysed as if it were forward SQL (e.g. a DROP in down()
        // tripping the Expand-phase gate). down() is isolated the same way for downSql.
        $upBody = $this->extractMethodBody($source, 'up');

        return $this->extractFromSource($upBody ?? $source);
    }

    /**
     * Returns the brace-delimited body of the named method, or null if not found.
     */
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
