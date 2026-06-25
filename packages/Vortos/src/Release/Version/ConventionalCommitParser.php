<?php

declare(strict_types=1);

namespace Vortos\Release\Version;

final class ConventionalCommitParser
{
    private const HEADER_PATTERN = '/^(?P<type>[a-z]+)(?:\((?P<scope>[^)]+)\))?(?P<bang>!)?:\s*(?P<desc>.+)$/';

    public function parse(string $rawMessage, string $sha): ConventionalCommit
    {
        $rawMessage = str_replace("\r\n", "\n", $rawMessage);
        $lines = explode("\n", trim($rawMessage));
        $header = (string) array_shift($lines);

        if (preg_match(self::HEADER_PATTERN, $header, $m) !== 1) {
            return new ConventionalCommit(
                type: 'other',
                scope: null,
                breaking: false,
                description: $header,
                body: implode("\n", $lines),
                footers: [],
                sha: $sha,
            );
        }

        $type = $m['type'];
        $scope = $m['scope'] !== '' ? $m['scope'] : null;
        $bang = $m['bang'] === '!';
        $description = trim($m['desc']);

        $body = '';
        $footers = [];
        $inBody = true;
        $bodyLines = [];

        foreach ($lines as $line) {
            if ($inBody && preg_match('/^[A-Za-z][A-Za-z0-9 -]*(?::\s|(?=\s#))/', $line)) {
                $inBody = false;
            }

            if ($inBody) {
                $bodyLines[] = $line;
            } else {
                $footers[] = $line;
            }
        }

        $body = trim(implode("\n", $bodyLines));

        $breaking = $bang || $this->hasBreakingFooter($footers);

        return new ConventionalCommit(
            type: $type,
            scope: $scope,
            breaking: $breaking,
            description: $description,
            body: $body,
            footers: $footers,
            sha: $sha,
        );
    }

    /** @param list<string> $footers */
    private function hasBreakingFooter(array $footers): bool
    {
        foreach ($footers as $footer) {
            if (str_starts_with($footer, 'BREAKING CHANGE:') || str_starts_with($footer, 'BREAKING-CHANGE:')) {
                return true;
            }
        }

        return false;
    }
}
