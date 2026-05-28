<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

final class LifecycleConfiguration
{
    /** @param list<array<string, mixed>> $rules */
    public function __construct(private readonly array $rules = []) {}

    public static function empty(): self
    {
        return new self();
    }

    /** @param array<string, mixed> $result */
    public static function fromS3Result(array $result): self
    {
        $rules = $result['Rules'] ?? [];
        return new self(is_array($rules) ? array_values($rules) : []);
    }

    /** @return list<array<string, mixed>> */
    public function rules(): array
    {
        return $this->rules;
    }

    public function hasRule(string $ruleId): bool
    {
        return $this->rule($ruleId) !== null;
    }

    /** @return array<string, mixed>|null */
    public function rule(string $ruleId): ?array
    {
        foreach ($this->rules as $rule) {
            if (($rule['ID'] ?? null) === $ruleId) {
                return $rule;
            }
        }

        return null;
    }

    public function withoutRule(string $ruleId): self
    {
        return new self(array_values(array_filter(
            $this->rules,
            static fn(array $rule): bool => ($rule['ID'] ?? null) !== $ruleId,
        )));
    }

    public function withRule(LifecycleRule $rule): self
    {
        return new self([...$this->withoutRule($rule->id())->rules(), $rule->toS3Rule()]);
    }

    /** @return array{Rules: list<array<string, mixed>>} */
    public function toS3LifecycleConfiguration(): array
    {
        return ['Rules' => $this->rules];
    }

    public function equals(self $other): bool
    {
        return $this->canonical($this->rules) === $this->canonical($other->rules());
    }

    /** @param list<array<string, mixed>> $rules */
    private function canonical(array $rules): string
    {
        usort($rules, static fn(array $a, array $b): int => strcmp((string) ($a['ID'] ?? ''), (string) ($b['ID'] ?? '')));

        return json_encode($rules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
