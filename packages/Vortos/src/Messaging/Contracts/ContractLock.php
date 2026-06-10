<?php

declare(strict_types=1);

namespace Vortos\Messaging\Contracts;

/**
 * The in-repo schema registry: a committed snapshot of every published wire
 * contract — logical name, version, and field schema (constructor parameter
 * names + types of the producing class).
 *
 * Convention-derived wire names are ergonomic but carry a trap: renaming an
 * event class silently renames the wire contract, breaking every consumer
 * and all in-flight messages. The lockfile turns that silent breakage into a
 * build failure:
 *
 *   vortos:contracts:lock   — snapshot current contracts into contracts.lock
 *   vortos:contracts:check  — diff live contracts against the lock; non-zero on drift
 *
 * The same diff runs at container compile time (skippable via
 * VORTOS_CONTRACTS_SKIP_CHECK=1 for intentional re-locks), so drift fails the
 * build, not production.
 *
 * Diff semantics — every drift names its remedy:
 *   wire name disappeared          → class renamed/moved: pin the old name with
 *                                    publish(..., as: '...') or re-lock intentionally
 *   schema changed, same version   → bump publish(..., version: N+1), add an
 *                                    upcaster, then re-lock
 *   version changed                → expected after a bump: re-lock
 */
final class ContractLock
{
    public const FILENAME = 'contracts.lock';

    /**
     * Computes the current contract snapshot from the compiled wire map.
     *
     * @param array<class-string, array{name: string, version: int}> $eventWireMap
     * @return array<string, array{class: string, version: int, schema: array<string, string>}> keyed by wire name
     */
    public static function compute(array $eventWireMap): array
    {
        $contracts = [];

        foreach ($eventWireMap as $class => $wire) {
            $schema = [];

            if (class_exists($class)) {
                $ctor = (new \ReflectionClass($class))->getConstructor();
                foreach ($ctor?->getParameters() ?? [] as $param) {
                    $type = $param->getType();
                    $schema[$param->getName()] = $type instanceof \ReflectionNamedType
                        ? ($type->allowsNull() && $type->getName() !== 'null' ? '?' : '') . $type->getName()
                        : (string) ($type ?? 'mixed');
                }
            }

            $contracts[$wire['name']] = [
                'class'   => $class,
                'version' => $wire['version'],
                'schema'  => $schema,
            ];
        }

        ksort($contracts);

        return $contracts;
    }

    /** @return array<string, array{class: string, version: int, schema: array<string, string>}>|null */
    public static function load(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? ($data['contracts'] ?? null) : null;
    }

    public static function write(string $path, array $contracts): void
    {
        file_put_contents($path, json_encode(
            [
                '_readme'   => 'Vortos wire contract lockfile. Regenerate with `vortos:contracts:lock` after INTENTIONAL contract changes. Commit this file.',
                'contracts' => $contracts,
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n");
    }

    /**
     * Diffs locked contracts against the current snapshot.
     *
     * @return list<string> Human-readable drift findings; empty = in sync
     */
    public static function diff(array $locked, array $current): array
    {
        $findings = [];

        foreach ($locked as $name => $lockedContract) {
            if (!isset($current[$name])) {
                $findings[] = sprintf(
                    "Contract '%s' (was %s v%d) no longer exists. If the class was renamed/moved, pin the original wire name with publish(%s::class, as: '%s'); if removal is intentional, re-run vortos:contracts:lock.",
                    $name,
                    $lockedContract['class'],
                    $lockedContract['version'],
                    self::shortClass($lockedContract['class']),
                    $name,
                );
                continue;
            }

            $currentContract = $current[$name];

            if ($currentContract['version'] !== $lockedContract['version']) {
                $findings[] = sprintf(
                    "Contract '%s' version changed v%d → v%d. If intentional (you added an upcaster), re-run vortos:contracts:lock.",
                    $name,
                    $lockedContract['version'],
                    $currentContract['version'],
                );
                continue; // schema change is expected alongside a version bump
            }

            if ($currentContract['schema'] !== $lockedContract['schema']) {
                $findings[] = sprintf(
                    "Contract '%s' payload schema changed WITHOUT a version bump (locked: %s | current: %s). Bump publish(..., version: %d), register an upcaster for old messages, then re-run vortos:contracts:lock.",
                    $name,
                    self::schemaString($lockedContract['schema']),
                    self::schemaString($currentContract['schema']),
                    $lockedContract['version'] + 1,
                );
            }
        }

        foreach (array_diff_key($current, $locked) as $name => $contract) {
            $findings[] = sprintf(
                "New contract '%s' (%s v%d) is not in the lockfile. Run vortos:contracts:lock to record it.",
                $name,
                $contract['class'],
                $contract['version'],
            );
        }

        return $findings;
    }

    private static function schemaString(array $schema): string
    {
        if ($schema === []) {
            return '(empty)';
        }

        return implode(', ', array_map(
            static fn(string $param, string $type) => "{$param}: {$type}",
            array_keys($schema),
            $schema,
        ));
    }

    private static function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
