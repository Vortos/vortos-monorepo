<?php

declare(strict_types=1);

namespace Vortos\Config\Service;

final readonly class ConfigPublishResult
{
    /**
     * @param string[] $published Files written to config/
     * @param string[] $skipped   Files that already existed and were not overwritten
     * @param string[] $unknown   Requested module names that have no stub
     */
    public function __construct(
        public array $published,
        public array $skipped,
        public array $unknown,
    ) {}
}
