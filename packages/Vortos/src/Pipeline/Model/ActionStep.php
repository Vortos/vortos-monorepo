<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

final readonly class ActionStep
{
    /**
     * @param array<string, string> $with
     * @param array<string, string> $env
     */
    public function __construct(
        public string $name,
        public PinnedAction $action,
        public array $with = [],
        public ?string $condition = null,
        public ?string $id = null,
        public array $env = [],
        /**
         * Emits `continue-on-error: true`, letting the job proceed when this step fails.
         *
         * Reserved for steps that RECORD something about a release rather than gate it. An SBOM
         * upload is the archetype: by the time it runs the image is already built, scanned and
         * signed, so a failure there says nothing about whether the artifact may ship — and it has
         * blocked a release over the runner's artifact-storage quota, which is not a property of
         * the code. A supply-chain record that can stop deploys is one somebody eventually deletes.
         */
        public bool $continueOnError = false,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Step name must be non-empty.');
        }

        if ($id !== null && preg_match('/^[a-z][a-z0-9_-]*$/', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Step id must match [a-z][a-z0-9_-]*, got "%s".',
                $id,
            ));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'type' => 'action',
            'name' => $this->name,
            'action' => $this->action->toArray(),
        ];

        if ($this->with !== []) {
            $with = $this->with;
            ksort($with);
            $data['with'] = $with;
        }

        if ($this->condition !== null) {
            $data['condition'] = $this->condition;
        }

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }

        if ($this->env !== []) {
            $env = $this->env;
            ksort($env);
            $data['env'] = $env;
        }

        return $data;
    }
}
