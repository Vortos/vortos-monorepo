<?php

declare(strict_types=1);

namespace Vortos\Release\Changelog;

final class ChangelogRenderer
{
    private const SECRET_PATTERNS = [
        '/(?:password|secret|token|api[_-]?key|private[_-]?key)\s*[:=]\s*\S+/i',
        '/(?:sk|rk|pk)_(?:live|test)_[a-zA-Z0-9]+/',
        '/ghp_[a-zA-Z0-9]{36}/',
        '/-----BEGIN (?:RSA |EC )?PRIVATE KEY-----/',
    ];

    public function render(Changelog $changelog): string
    {
        $lines = [];
        $lines[] = sprintf('## [%s] - %s', (string) $changelog->version, $changelog->date->format('Y-m-d'));
        $lines[] = '';

        if ($changelog->isEmpty()) {
            $lines[] = 'No notable changes.';
            $lines[] = '';

            return implode("\n", $lines);
        }

        foreach ($changelog->grouped as $group => $entries) {
            $lines[] = '### ' . Changelog::labelForGroup($group);
            $lines[] = '';

            foreach ($entries as $entry) {
                $line = '- ';
                if ($entry->scope !== null) {
                    $line .= '**' . $entry->scope . ':** ';
                }
                $line .= $this->redact($entry->description);

                $provenance = substr($entry->sha, 0, 7);
                if ($entry->buildId !== null) {
                    $provenance .= ', build ' . $entry->buildId;
                }
                $line .= ' (' . $provenance . ')';

                $lines[] = $line;
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function redact(string $text): string
    {
        foreach (self::SECRET_PATTERNS as $pattern) {
            $text = (string) preg_replace($pattern, '***REDACTED***', $text);
        }

        return $text;
    }
}
