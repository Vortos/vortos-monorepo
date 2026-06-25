<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

final readonly class InvariantResult
{
    private function __construct(
        public string $name,
        public bool $passed,
        public string $detail,
    ) {}

    public static function pass(string $name, string $detail = ''): self
    {
        return new self($name, true, $detail);
    }

    public static function fail(string $name, string $detail): self
    {
        return new self($name, false, $detail);
    }

    /** @return array{name:string, passed:bool, detail:string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'passed' => $this->passed, 'detail' => $this->detail];
    }
}
