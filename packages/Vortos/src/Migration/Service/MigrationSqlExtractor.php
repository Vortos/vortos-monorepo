<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

/**
 * Extracts raw SQL strings from addSql() calls in a Doctrine migration class file.
 *
 * ## Why the PHP tokenizer, not a regex
 *
 * This used to match `->addSql('…')` with a regex that captured a single string literal.
 * That silently TRUNCATED every call whose argument was built by concatenation — the
 * normal way to keep a long statement readable:
 *
 *     $this->addSql(
 *         'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_applications_consented_version'
 *         . ' ON applications (consented_at_version_id)'
 *     );
 *
 * The extractor returned only the first line, so the `ON applications (…)` half vanished.
 * The result still looked like a statement, which is what made it dangerous: the safety
 * analyzer inspected a statement with no table and no operation, found nothing to flag,
 * and passed. Every lock hazard living in the tail of a concatenated statement was
 * invisible to the CI gate. Replaying the extracted SQL (down-verify) failed with
 * "syntax error at end of input" — the only symptom this bug ever produced.
 *
 * Tokenising removes the class of bug rather than patching the pattern: the parser that
 * decides where an argument ends is now PHP's own.
 *
 * ## Handled string forms
 *
 *   $this->addSql('…')                    single-quoted
 *   $this->addSql("…")                    double-quoted (no interpolation)
 *   $this->addSql(<<<'SQL' … SQL)         heredoc / nowdoc
 *   any concatenation of the above with `.`
 *
 * ## Known limitation
 *
 * An argument that is not statically resolvable — a variable, sprintf(), or an
 * interpolated double-quoted string — cannot be recovered from source and is skipped.
 * Such a statement is invisible to the analyzer, so migrations that must be gated should
 * keep their SQL as literals. Resolving these would require executing the migration.
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
        return $this->extractFromClassMethod($className, 'up');
    }

    /**
     * Same as {@see extractFromClass()} for an arbitrary method — `down()` in particular,
     * whose SQL must never be analysed as if it were forward SQL (a DROP in down() would
     * trip the Expand-phase gate).
     *
     * @return string[]
     */
    public function extractFromClassMethod(string $className, string $method): array
    {
        $source = $this->readClassSource($className);

        if ($source === null) {
            return [];
        }

        $body = $this->extractMethodBody($source, $method);

        // Falling back to the whole file for up() preserves long-standing behaviour for
        // migrations whose method the brace scanner cannot find; down() has no such
        // fallback, since scanning the file would pick up the up() statements.
        if ($body === null) {
            return $method === 'up' ? $this->extractFromSource($source) : [];
        }

        return $this->extractFromSource($body);
    }

    private function readClassSource(string $className): ?string
    {
        if (!class_exists($className)) {
            return null;
        }

        try {
            $file = (new \ReflectionClass($className))->getFileName();
        } catch (\ReflectionException) {
            return null;
        }

        if ($file === false || !is_readable($file)) {
            return null;
        }

        $source = file_get_contents($file);

        return $source === false ? null : $source;
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
        // token_get_all() needs an opening tag; a method body extracted from a class has
        // none, while a whole file already does. Prefixing unconditionally would corrupt
        // the latter, so only bodies get wrapped.
        $tokens = @token_get_all(str_contains($source, '<?php') ? $source : '<?php ' . $source);

        $sql = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!$this->isAddSqlCallAt($tokens, $i)) {
                continue;
            }

            // Advance to the '(' that opens the argument list.
            $open = $this->nextSignificant($tokens, $i + 1);
            while ($open !== null && $tokens[$open] !== '(') {
                $open = $this->nextSignificant($tokens, $open + 1);
            }

            if ($open === null) {
                continue;
            }

            $statement = $this->readFirstArgument($tokens, $open + 1);

            if ($statement !== null && trim($statement) !== '') {
                $sql[] = trim($statement);
            }
        }

        return $sql;
    }

    /**
     * True when tokens[$i] begins a `->addSql` / `?->addSql` call.
     *
     * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
     */
    private function isAddSqlCallAt(array $tokens, int $i): bool
    {
        $token = $tokens[$i];

        if (!is_array($token) || !in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return false;
        }

        $next = $this->nextSignificant($tokens, $i + 1);

        return $next !== null
            && is_array($tokens[$next])
            && $tokens[$next][0] === T_STRING
            && strcasecmp($tokens[$next][1], 'addSql') === 0;
    }

    /**
     * Concatenates the string literals of the first argument, starting at $from (just
     * inside the opening paren). Returns null when the argument is not statically
     * resolvable — a variable, a function call, or an interpolated string — because a
     * partially recovered statement is worse than none: it reads as valid SQL while
     * omitting whatever the analyzer most needs to see.
     *
     * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
     */
    private function readFirstArgument(array $tokens, int $from): ?string
    {
        $value = '';
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                // End of the first argument: the closing paren, or a comma separating it
                // from a second argument.
                if ($token === ')' || $token === ',') {
                    return $value;
                }

                if ($token === '.') {
                    continue; // concatenation — keep accumulating
                }

                // '(' or '[' would mean a call or array; anything else is an operator.
                // Either way the value is not statically knowable.
                return null;
            }

            switch ($token[0]) {
                case T_CONSTANT_ENCAPSED_STRING:
                    $value .= $this->unquote($token[1]);
                    break;

                case T_START_HEREDOC:
                case T_END_HEREDOC:
                    break;

                case T_ENCAPSED_AND_WHITESPACE:
                    // Heredoc/nowdoc body. An interpolated double-quoted string also emits
                    // this, but always alongside a '"' string token, which returns null
                    // above before we get here.
                    $value .= $token[1];
                    break;

                case T_WHITESPACE:
                case T_COMMENT:
                case T_DOC_COMMENT:
                    break;

                default:
                    return null; // variable, constant, function name, …
            }
        }

        return null;
    }

    /**
     * Turns a quoted PHP string literal into its runtime value.
     */
    private function unquote(string $literal): string
    {
        if ($literal === '') {
            return '';
        }

        $quote = $literal[0];
        $inner = substr($literal, 1, -1);

        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }

        return stripcslashes($inner);
    }

    /**
     * Index of the next token that is not whitespace or a comment.
     *
     * @param array<int, array{0:int, 1:string, 2:int}|string> $tokens
     */
    private function nextSignificant(array $tokens, int $from): ?int
    {
        $count = count($tokens);

        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}
