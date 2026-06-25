<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final readonly class Trigger
{
    /**
     * @param list<string> $branches
     * @param list<string> $tags
     * @param list<string> $paths
     */
    public function __construct(
        public TriggerEvent $event,
        public array $branches = [],
        public array $tags = [],
        public array $paths = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['event' => $this->event->value];

        if ($this->branches !== []) {
            $data['branches'] = $this->branches;
        }

        if ($this->tags !== []) {
            $data['tags'] = $this->tags;
        }

        if ($this->paths !== []) {
            $data['paths'] = $this->paths;
        }

        return $data;
    }
}
