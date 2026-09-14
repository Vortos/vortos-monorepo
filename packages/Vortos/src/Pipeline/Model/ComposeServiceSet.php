<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * The compose services allowed to receive a host env file. Never empty: a secret nobody may mount is a
 * secret that should not be delivered.
 */
final readonly class ComposeServiceSet
{
    /** @var non-empty-list<string> */
    public array $names;

    /** @param list<string> $names */
    public function __construct(array $names)
    {
        if ($names === []) {
            throw new \InvalidArgumentException('A host env file must name at least one compose service allowed to mount it.');
        }

        foreach ($names as $name) {
            // config/pipeline.php is untyped PHP; the names are compared against a parsed compose file.
            // @phpstan-ignore function.alreadyNarrowedType
            if (!\is_string($name) || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $name) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Compose service names must match [a-zA-Z0-9][a-zA-Z0-9_.-]*, got "%s".',
                    // @phpstan-ignore function.alreadyNarrowedType
                    \is_string($name) ? $name : get_debug_type($name),
                ));
            }
        }

        if (\count(array_unique($names)) !== \count($names)) {
            throw new \InvalidArgumentException(sprintf('Compose service names must be unique, got [%s].', implode(', ', $names)));
        }

        $this->names = $names;
    }

    public function contains(string $service): bool
    {
        return \in_array($service, $this->names, true);
    }
}
