<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

use Symfony\Contracts\Service\ResetInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

final class FlagRegistry implements FlagRegistryInterface, ResetInterface
{
    /** Per-request in-memory cache — avoids repeated storage reads within one request. */
    private array $resolved = [];

    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagEvaluator $evaluator,
    ) {}

    public function isEnabled(string $name, FlagContext $context = new FlagContext()): bool
    {
        $key = $name . '|' . ($context->userId ?? '__anon__') . '|' . md5(serialize($context->attributes));

        if (!array_key_exists($key, $this->resolved)) {
            $flag              = $this->storage->findByName($name);
            $this->resolved[$key] = $flag !== null && $this->evaluator->evaluate($flag, $context);
        }

        return $this->resolved[$key];
    }

    public function variant(string $name, FlagContext $context = new FlagContext()): string
    {
        $flag = $this->storage->findByName($name);

        if ($flag === null) {
            return 'control';
        }

        return $this->evaluator->evaluateVariant($flag, $context);
    }

    /**
     * Returns names of all flags enabled for the given context.
     * Used by the /api/flags endpoint to send the full flag state to the frontend.
     *
     * @return array{flags: list<string>, variants: array<string, string>}
     */
    public function reset(): void
    {
        $this->resolved = [];
    }

    public function allForContext(FlagContext $context = new FlagContext()): array
    {
        $flags    = [];
        $variants = [];

        foreach ($this->storage->findAll() as $flag) {
            if ($this->evaluator->evaluate($flag, $context)) {
                $flags[] = $flag->name;
            }

            if ($flag->isVariant()) {
                $v = $this->evaluator->evaluateVariant($flag, $context);
                if ($v !== 'control') {
                    $variants[$flag->name] = $v;
                }
            }
        }

        return ['flags' => $flags, 'variants' => $variants];
    }
}
