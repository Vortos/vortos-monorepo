<?php

declare(strict_types=1);

namespace Vortos\Release\Version;

final class BumpCalculator
{
    /**
     * @param list<ConventionalCommit> $commits
     */
    public function calculate(array $commits, bool $preStable = false): BumpLevel
    {
        $highest = BumpLevel::None;

        foreach ($commits as $commit) {
            $highest = BumpLevel::max($highest, $commit->toBumpLevel());
        }

        if ($preStable && $highest !== BumpLevel::None) {
            $highest = $this->demote($highest);
        }

        return $highest;
    }

    private function demote(BumpLevel $level): BumpLevel
    {
        return match ($level) {
            BumpLevel::Major => BumpLevel::Minor,
            BumpLevel::Minor => BumpLevel::Patch,
            BumpLevel::Patch => BumpLevel::Patch,
            BumpLevel::None => BumpLevel::None,
        };
    }
}
